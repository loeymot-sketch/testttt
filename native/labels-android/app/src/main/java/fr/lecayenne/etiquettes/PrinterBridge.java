package fr.lecayenne.etiquettes;

import android.Manifest;
import android.app.Activity;
import android.app.AlertDialog;
import android.bluetooth.BluetoothAdapter;
import android.bluetooth.BluetoothDevice;
import android.bluetooth.BluetoothManager;
import android.bluetooth.BluetoothSocket;
import android.content.Context;
import android.content.DialogInterface;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.text.InputType;
import android.util.Base64;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;
import android.widget.EditText;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.IOException;
import java.io.InputStream;
import java.io.OutputStream;
import java.lang.reflect.Method;
import java.net.InetSocketAddress;
import java.net.Socket;
import java.util.ArrayList;
import java.util.List;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

/**
 * Pont `window.LabelNative` : transporte les OCTETS entre la page web et la Brother. Rien d'autre — le protocole raster,
 * l'état, les confirmations de page sont dans la page (brotherQl.js / labelPrinter.js), déjà prouvés contre le manuel.
 *
 * Java → JS : `window.__labelNativeEvent(json)` avec
 *   {type:"result", callId, ok, name?, error?, device?}   réponse à un appel
 *   {type:"data", b64}                                    octets lus sur l'imprimante
 *   {type:"closed", reason?}                              la liaison est tombée
 */
public class PrinterBridge {
    private static final UUID SPP = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB");
    private static final String PREFS = "etiquettes";
    private static final String KEY_SAVED = "printer";
    private static final String KEY_LAST_IP = "last_ip";

    private final Activity activity;
    private final WebView web;
    private final ExecutorService io = Executors.newSingleThreadExecutor();
    private final Object lock = new Object();

    private Socket tcp;
    private BluetoothSocket bt;
    private InputStream in;
    private OutputStream out;
    private int generation = 0;
    private JSONObject lastChoice;
    // Prise en cours de connexion : fermable depuis un autre fil pour AVORTER un connect() bloqué (Bluetooth : pas de délai).
    private Socket connectingTcp;
    private BluetoothSocket connectingBt;
    private String connectedTarget;
    private String pendingChooserCall;
    private final Handler ui = new Handler(Looper.getMainLooper());
    private static final long CONNECT_WATCHDOG_MS = 15000;

    public PrinterBridge(Activity activity, WebView web) {
        this.activity = activity;
        this.web = web;
    }

    // ── appels depuis la page ────────────────────────────────────────────────────────────

    @JavascriptInterface
    public String version() {
        return BuildConfig.VERSION_NAME;
    }

    /** Dernier appareil dont l'ouverture a réussi : reconnexion sans geste. */
    @JavascriptInterface
    public String saved() {
        return prefs().getString(KEY_SAVED, "null");
    }

    /** Imprimantes Bluetooth appairées dans Android : [{name,address}]. */
    @JavascriptInterface
    public String listBonded() {
        JSONArray arr = new JSONArray();
        try {
            for (BluetoothDevice d : bondedDevices()) {
                JSONObject o = new JSONObject();
                o.put("name", d.getName());
                o.put("address", d.getAddress());
                arr.put(o);
            }
        } catch (Exception ignored) { /* permission refusée ou pas de Bluetooth : liste vide */ }
        return arr.toString();
    }

    /** Fenêtre native : imprimantes Bluetooth appairées + « Adresse IP (Wi-Fi) ». */
    @JavascriptInterface
    public void chooseDevice(final String callId) {
        activity.runOnUiThread(() -> showChooser(callId));
    }

    @JavascriptInterface
    public void open(final String callId, final String kind, final String target, final int port) {
        io.execute(() -> doOpen(callId, kind, target, port));
    }

    @JavascriptInterface
    public void write(final String callId, final String b64) {
        io.execute(() -> doWrite(callId, b64));
    }

    @JavascriptInterface
    public void close() {
        closeLink(false, null); // direct : ne fait pas la queue derrière une connexion qui traîne
    }

    public void shutdown() {
        closeLink(false, null);
        io.shutdownNow();
    }

    // ── ouverture / lecture / écriture ───────────────────────────────────────────────────

    private void doOpen(String callId, String kind, String target, int port) {
        final String key = kind + ":" + target + ":" + port;
        synchronized (lock) {
            // La page rechargée redemande la MÊME imprimante alors que la liaison tient encore : on la garde (une Brother
            // refuse souvent une reconnexion immédiate).
            if (out != null && key.equals(connectedTarget)) {
                result(callId, true, null, null);
                return;
            }
        }
        closeLink(false, null); // une seule liaison à la fois
        try {
            InputStream is;
            OutputStream os;
            Socket tcpSocket = null;
            BluetoothSocket btSocket = null;
            if ("tcp".equals(kind)) {
                tcpSocket = new Socket();
                synchronized (lock) { connectingTcp = tcpSocket; }
                tcpSocket.connect(new InetSocketAddress(target, port > 0 ? port : 9100), 6000);
                tcpSocket.setTcpNoDelay(true);
                is = tcpSocket.getInputStream();
                os = tcpSocket.getOutputStream();
            } else {
                btSocket = connectBluetooth(target);
                is = btSocket.getInputStream();
                os = btSocket.getOutputStream();
            }
            final int gen;
            synchronized (lock) {
                tcp = tcpSocket;
                bt = btSocket;
                in = is;
                out = os;
                connectedTarget = key;
                connectingTcp = null;
                connectingBt = null;
                gen = ++generation;
            }
            commitChoice(kind, target, port);
            startReader(gen, is);
            result(callId, true, null, null);
        } catch (SecurityException e) {
            synchronized (lock) { connectingTcp = null; connectingBt = null; }
            result(callId, false, "NetworkError",
                    "Autorisez « Appareils à proximité » pour cette application (Réglages › Applications › Le Cayenne Étiquettes › Autorisations).");
        } catch (Exception e) {
            synchronized (lock) { connectingTcp = null; connectingBt = null; }
            String msg = e.getMessage() == null ? e.getClass().getSimpleName() : e.getMessage();
            result(callId, false, "NetworkError", "Imprimante injoignable (" + msg + ")");
        }
    }

    private BluetoothSocket connectBluetooth(String address) throws IOException {
        BluetoothAdapter adapter = adapter();
        if (adapter == null || !adapter.isEnabled()) throw new IOException("Bluetooth éteint");
        BluetoothDevice device = adapter.getRemoteDevice(address);
        // Aucune recherche n'est lancée par l'application ; sur Android 12+, cancelDiscovery() exige BLUETOOTH_SCAN et peut
        // lever SecurityException même avec « Appareils à proximité » accordé : jamais bloquant.
        try { adapter.cancelDiscovery(); } catch (SecurityException ignored) { /* inutile ici */ }
        BluetoothSocket socket = null;
        try {
            socket = device.createRfcommSocketToServiceRecord(SPP);
            armWatchdog(socket);
            socket.connect();
            return socket;
        } catch (IOException first) {
            // Certaines imprimantes n'annoncent pas le profil série dans leur annuaire (SDP) : on ouvre le canal 1 directement.
            if (socket != null) try { socket.close(); } catch (IOException ignored) { /* déjà fermé */ }
            try {
                Method m = device.getClass().getMethod("createRfcommSocket", int.class);
                BluetoothSocket fallback = (BluetoothSocket) m.invoke(device, 1);
                armWatchdog(fallback);
                fallback.connect();
                return fallback;
            } catch (Exception second) {
                throw first;
            }
        }
    }

    /** Un connect() Bluetooth n'a pas de délai propre : au bout de 15 s on ferme la prise, ce qui l'interrompt. */
    private void armWatchdog(final BluetoothSocket socket) {
        synchronized (lock) { connectingBt = socket; }
        ui.postDelayed(() -> {
            boolean stuck;
            synchronized (lock) { stuck = connectingBt == socket && bt != socket; }
            if (stuck) try { socket.close(); } catch (IOException ignored) { /* abandon */ }
        }, CONNECT_WATCHDOG_MS);
    }

    private void startReader(final int gen, final InputStream is) {
        Thread t = new Thread(() -> {
            byte[] buf = new byte[1024];
            try {
                int n;
                while ((n = is.read(buf)) >= 0) {
                    if (n == 0) continue;
                    JSONObject o = new JSONObject();
                    o.put("type", "data");
                    o.put("b64", Base64.encodeToString(buf, 0, n, Base64.NO_WRAP));
                    emit(o);
                }
            } catch (Exception ignored) { /* la liaison est tombée : ci-dessous */ }
            closeLinkIfCurrent(gen, "Imprimante déconnectée");
        }, "label-reader");
        t.setDaemon(true);
        t.start();
    }

    private void doWrite(String callId, String b64) {
        OutputStream os;
        synchronized (lock) { os = out; }
        if (os == null) {
            result(callId, false, "NetworkError", "Imprimante non connectée");
            return;
        }
        try {
            os.write(Base64.decode(b64, Base64.DEFAULT));
            os.flush();
            result(callId, true, null, null);
        } catch (Exception e) {
            result(callId, false, "NetworkError", "Écriture impossible (" + e.getMessage() + ")");
            closeLink(true, "Imprimante déconnectée");
        }
    }

    /** Ferme la liaison SEULEMENT si c'est encore la génération `gen` (un lecteur mourant ne ferme pas la liaison neuve). */
    private void closeLinkIfCurrent(int gen, String reason) {
        synchronized (lock) {
            if (gen != generation) return;
        }
        closeLink(true, reason);
    }

    private void closeLink(boolean notify, String reason) {
        boolean had;
        synchronized (lock) {
            had = out != null || in != null;
            generation++; // tout lecteur en cours devient obsolète
            try { if (tcp != null) tcp.close(); } catch (IOException ignored) { /* déjà fermé */ }
            try { if (bt != null) bt.close(); } catch (IOException ignored) { /* déjà fermé */ }
            // une connexion en cours est avortée aussi (close() la débloque)
            try { if (connectingTcp != null) connectingTcp.close(); } catch (IOException ignored) { /* déjà fermé */ }
            try { if (connectingBt != null) connectingBt.close(); } catch (IOException ignored) { /* déjà fermé */ }
            connectingTcp = null; connectingBt = null; connectedTarget = null;
            tcp = null; bt = null; in = null; out = null;
        }
        if (notify && had) {
            try {
                JSONObject o = new JSONObject();
                o.put("type", "closed");
                if (reason != null) o.put("reason", reason);
                emit(o);
            } catch (Exception ignored) { /* rien */ }
        }
    }

    // ── choix de l'imprimante ────────────────────────────────────────────────────────────

    private boolean needsBluetoothPermission() {
        return Build.VERSION.SDK_INT >= 31
                && activity.checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED;
    }

    /** MainActivity → réponse à la demande « Appareils à proximité » : on rouvre le choix, sans redemander (pas de boucle). */
    public void onBluetoothPermissionResult() {
        final String call;
        synchronized (lock) { call = pendingChooserCall; pendingChooserCall = null; }
        if (call != null) activity.runOnUiThread(() -> showChooser(call, true));
    }

    private void showChooser(final String callId) {
        showChooser(callId, false);
    }

    private void showChooser(final String callId, boolean permissionAlreadyAsked) {
        if (!permissionAlreadyAsked && needsBluetoothPermission()) {
            // L'utilisateur peut appuyer sur « Connecter » avant d'avoir répondu à la demande du lancement : on la repose ici.
            synchronized (lock) { pendingChooserCall = callId; }
            activity.requestPermissions(new String[]{Manifest.permission.BLUETOOTH_CONNECT}, MainActivity.REQ_BT);
            return;
        }
        final List<JSONObject> devices = new ArrayList<>();
        final List<String> labels = new ArrayList<>();
        String problem = null;
        try {
            List<BluetoothDevice> bonded = new ArrayList<>(bondedDevices());
            // Les Brother QL d'abord
            bonded.sort((a, b) -> Boolean.compare(!isBrother(a), !isBrother(b)));
            for (BluetoothDevice d : bonded) {
                JSONObject o = new JSONObject();
                o.put("kind", "bt");
                o.put("address", d.getAddress());
                o.put("name", d.getName());
                devices.add(o);
                labels.add((d.getName() == null ? "Appareil" : d.getName()) + "  ·  Bluetooth\n" + d.getAddress());
            }
        } catch (SecurityException e) {
            problem = "Autorisez « Appareils à proximité » pour lister les imprimantes Bluetooth.";
        } catch (Exception e) {
            problem = "Bluetooth indisponible sur cette tablette.";
        }
        labels.add("Adresse IP (Wi‑Fi)…");

        AlertDialog.Builder b = new AlertDialog.Builder(activity);
        b.setTitle("Choisir l’imprimante");
        if (devices.isEmpty() && problem == null) {
            b.setMessage("Aucune imprimante Bluetooth appairée. Appairez la QL‑820NWB dans Réglages › Bluetooth, ou saisissez son adresse IP (Wi‑Fi).");
            b.setPositiveButton("Adresse IP (Wi‑Fi)…", (d, w) -> askIp(callId));
        } else if (problem != null) {
            b.setMessage(problem);
            b.setPositiveButton("Adresse IP (Wi‑Fi)…", (d, w) -> askIp(callId));
        } else {
            b.setItems(labels.toArray(new String[0]), (d, which) -> {
                if (which < devices.size()) chosen(callId, devices.get(which));
                else askIp(callId);
            });
        }
        b.setNegativeButton("Annuler", (d, w) -> cancelled(callId));
        b.setOnCancelListener(d -> cancelled(callId));
        b.show();
    }

    private void askIp(final String callId) {
        final EditText input = new EditText(activity);
        input.setInputType(InputType.TYPE_CLASS_TEXT | InputType.TYPE_TEXT_VARIATION_URI);
        input.setHint("192.168.1.50");
        input.setText(prefs().getString(KEY_LAST_IP, ""));
        new AlertDialog.Builder(activity)
                .setTitle("Adresse IP de l’imprimante")
                .setMessage("Imprimante et tablette sur le même Wi‑Fi. L’adresse s’affiche sur l’écran de l’imprimante (Menu › WLAN) ; port 9100.")
                .setView(input)
                .setPositiveButton("Utiliser", (DialogInterface d, int w) -> {
                    String host = input.getText().toString().trim();
                    if (host.isEmpty()) { cancelled(callId); return; }
                    prefs().edit().putString(KEY_LAST_IP, host).apply();
                    try {
                        JSONObject o = new JSONObject();
                        o.put("kind", "tcp");
                        o.put("host", host);
                        o.put("port", 9100);
                        o.put("name", "Wi‑Fi " + host);
                        chosen(callId, o);
                    } catch (Exception e) { cancelled(callId); }
                })
                .setNegativeButton("Annuler", (d, w) -> cancelled(callId))
                .setOnCancelListener(d -> cancelled(callId))
                .show();
    }

    private void chosen(String callId, JSONObject device) {
        synchronized (lock) { lastChoice = device; }
        result(callId, true, null, device);
    }

    private void cancelled(String callId) {
        result(callId, false, "NotFoundError", "Aucune imprimante choisie.");
    }

    private boolean isBrother(BluetoothDevice d) {
        try {
            String n = d.getName();
            return n != null && (n.toUpperCase().startsWith("QL") || n.toUpperCase().contains("BROTHER"));
        } catch (SecurityException e) {
            return false;
        }
    }

    // ── utilitaires ──────────────────────────────────────────────────────────────────────

    private void commitChoice(String kind, String target, int port) {
        try {
            JSONObject saved = null;
            synchronized (lock) {
                if (lastChoice != null) {
                    boolean same = "tcp".equals(kind)
                            ? target.equals(lastChoice.optString("host"))
                            : target.equalsIgnoreCase(lastChoice.optString("address"));
                    if (same) saved = lastChoice;
                }
            }
            if (saved == null) {
                saved = new JSONObject();
                saved.put("kind", kind);
                saved.put(("tcp".equals(kind)) ? "host" : "address", target);
                saved.put("port", port > 0 ? port : 9100);
            }
            prefs().edit().putString(KEY_SAVED, saved.toString()).apply();
        } catch (Exception ignored) { /* mémorisation facultative */ }
    }

    private BluetoothAdapter adapter() {
        BluetoothManager m = (BluetoothManager) activity.getSystemService(Context.BLUETOOTH_SERVICE);
        return m != null ? m.getAdapter() : null;
    }

    private Set<BluetoothDevice> bondedDevices() {
        BluetoothAdapter a = adapter();
        if (a == null) throw new IllegalStateException("pas de Bluetooth");
        return a.getBondedDevices();
    }

    private SharedPreferences prefs() {
        return activity.getSharedPreferences(PREFS, Context.MODE_PRIVATE);
    }

    private void result(String callId, boolean ok, String name, Object payload) {
        try {
            JSONObject o = new JSONObject();
            o.put("type", "result");
            o.put("callId", callId);
            o.put("ok", ok);
            if (!ok) {
                o.put("name", name == null ? "NetworkError" : name);
                o.put("error", payload == null ? "Erreur" : String.valueOf(payload));
            } else if (payload instanceof JSONObject) {
                o.put("device", payload);
            }
            emit(o);
        } catch (Exception ignored) { /* rien */ }
    }

    private void emit(JSONObject o) {
        final String js = "window.__labelNativeEvent&&window.__labelNativeEvent(" + JSONObject.quote(o.toString()) + ")";
        web.post(() -> web.evaluateJavascript(js, null));
    }
}
