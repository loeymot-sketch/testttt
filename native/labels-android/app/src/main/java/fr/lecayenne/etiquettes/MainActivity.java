package fr.lecayenne.etiquettes;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.view.View;
import android.view.WindowManager;
import android.webkit.PermissionRequest;
import android.webkit.RenderProcessGoneDetail;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.FrameLayout;
import android.widget.Toast;

/**
 * « Le Cayenne Étiquettes » — le back-office (écran Étiquettes) dans une WebView, avec un pont Java vers l'imprimante
 * Brother QL-820NWB : vraie prise Bluetooth RFCOMM (profil série) ou TCP Wi-Fi (port 9100). Chrome n'est plus dans la
 * boucle ; le protocole d'impression (octets, état, confirmations) reste celui de la page web.
 *
 * L'écran reste allumé : une tablette posée près de l'imprimante imprime les commandes qui arrivent.
 */
public class MainActivity extends Activity {
    static final int REQ_BT = 41;
    static final int REQ_MIC = 42;

    /** Demande micro de la page en attente de la réponse de l'utilisateur à la permission Android. */
    private PermissionRequest pendingMicRequest;

    /** Téléchargement de mise à jour en cours (identifiant DownloadManager), -1 si aucun. */
    private long updateDownloadId = -1;
    private BroadcastReceiver updateReceiver;

    private WebView web;
    private PrinterBridge bridge;
    private long lastRenderCrash = 0;

    @SuppressLint({"SetJavaScriptEnabled", "AddJavascriptInterface"})
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        FrameLayout root = new FrameLayout(this);
        web = new WebView(this);
        root.addView(web, new FrameLayout.LayoutParams(FrameLayout.LayoutParams.MATCH_PARENT, FrameLayout.LayoutParams.MATCH_PARENT));
        setContentView(root);
        // Android 15+ dessine sous les barres système : on laisse la place à la barre d'état et de navigation.
        root.setOnApplyWindowInsetsListener((v, insets) -> {
            v.setPadding(insets.getSystemWindowInsetLeft(), insets.getSystemWindowInsetTop(),
                    insets.getSystemWindowInsetRight(), insets.getSystemWindowInsetBottom());
            return insets;
        });

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setUserAgentString(s.getUserAgentString() + " LeCayenneEtiquettes/" + BuildConfig.VERSION_NAME);
        WebView.setWebContentsDebuggingEnabled(BuildConfig.DEBUG);

        final String startUrl = startUrl();
        final String host = Uri.parse(startUrl).getHost();
        web.setWebChromeClient(new WebChromeClient() {
            // [DICTÉE 2026-10-19] La page (getUserMedia) demande le micro : la WebView ne l'accorde JAMAIS toute seule — sans ce
            // relais la demande échoue en silence (« NotAllowedError ») et l'autorisation n'apparaît nulle part dans Android.
            @Override
            public void onPermissionRequest(final PermissionRequest request) {
                runOnUiThread(() -> handleWebPermission(request, host));
            }

            @Override
            public void onPermissionRequestCanceled(PermissionRequest request) {
                if (pendingMicRequest == request) pendingMicRequest = null;
            }
        });
        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri u = request.getUrl();
                if (host != null && host.equalsIgnoreCase(u.getHost())) return false;
                try {
                    startActivity(new Intent(Intent.ACTION_VIEW, u)); // un lien externe s'ouvre dans le navigateur
                } catch (Exception ignored) { /* rien pour l'ouvrir */ }
                return true;
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                // [GARDE DE CONNEXION 2026-10-19 · owner « je ne veux plus de blocage, je rafraîchis plusieurs fois »] Wi‑Fi coupé au
                // lancement ou longtemps : plus de page d'erreur muette. Un écran clair « Pas de connexion », qui vérifie toutes les
                // 4 s si le serveur répond et rouvre l'application TOUT SEUL dès que c'est le cas.
                if (request.isForMainFrame()) showOfflinePage(view, startUrl);
            }

            @Override
            public boolean onRenderProcessGone(WebView view, RenderProcessGoneDetail detail) {
                // le moteur web est tombé (mémoire) : on repart propre, la session est conservée — mais pas en boucle
                long now = System.currentTimeMillis();
                if (now - lastRenderCrash > 20000) { lastRenderCrash = now; recreate(); }
                else view.postDelayed(() -> view.loadUrl(startUrl), 20000);
                return true;
            }
        });

        // Mise à jour de l'application depuis le serveur : le site propose « Mettre à jour », le téléchargement du fichier
        // .apk est pris en charge ici (téléchargement Android puis installation, avec la confirmation du système).
        web.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            Uri u = Uri.parse(url);
            boolean notreSite = host != null && host.equalsIgnoreCase(u.getHost());
            String chemin = u.getPath() == null ? "" : u.getPath().toLowerCase();
            if (notreSite && chemin.endsWith(".apk")) startUpdate(url);
            else try { startActivity(new Intent(Intent.ACTION_VIEW, u)); } catch (Exception ignored) { /* rien pour l'ouvrir */ }
        });
        registerUpdateReceiver();

        bridge = new PrinterBridge(this, web);
        web.addJavascriptInterface(bridge, "LabelNative");

        // Android 12+ : « Appareils à proximité » (BLUETOOTH_CONNECT) est une permission d'exécution.
        if (Build.VERSION.SDK_INT >= 31 && checkSelfPermission(Manifest.permission.BLUETOOTH_CONNECT) != PackageManager.PERMISSION_GRANTED) {
            requestPermissions(new String[]{Manifest.permission.BLUETOOTH_CONNECT}, REQ_BT);
        }

        // restoreState() renvoie null quand l'état sauvegardé ne contient pas de WebView (recreate() après un plantage) :
        // sans cette garde, la page restait blanche.
        if (savedInstanceState == null || web.restoreState(savedInstanceState) == null) web.loadUrl(startUrl);
    }

    /** Écran local affiché quand le serveur est injoignable : il se rouvre seul dès que la connexion revient. */
    private void showOfflinePage(WebView view, String startUrl) {
        String html = "<html><head><meta name='viewport' content='width=device-width,initial-scale=1'></head>"
                + "<body style='margin:0;font-family:sans-serif;background:#111;color:#fff;display:flex;flex-direction:column;"
                + "align-items:center;justify-content:center;height:100vh;text-align:center'>"
                + "<div style='font-size:56px'>&#128268;</div>"
                + "<div style='font-size:26px;font-weight:700;margin:12px 0'>Pas de connexion</div>"
                + "<div style='font-size:18px;opacity:.85'>Reconnexion automatique&hellip; rien &agrave; faire.</div>"
                + "<button onclick=\"location.href='" + startUrl + "'\" style='margin-top:22px;background:#F4501E;color:#fff;border:0;"
                + "border-radius:10px;padding:14px 26px;font-size:20px;font-weight:700'>R&eacute;essayer maintenant</button>"
                + "<script>setInterval(function(){fetch('" + startUrl.replaceAll("^(https?://[^/]+).*$", "$1")
                + "/api/health/live',{mode:'no-cors',cache:'no-store'}).then(function(){location.href='" + startUrl + "';}).catch(function(){});},4000);</script>"
                + "</body></html>";
        view.loadDataWithBaseURL(startUrl, html, "text/html", "utf-8", null);
    }

    /** Télécharge la nouvelle version (gestionnaire de téléchargements Android) ; l'installation démarre à la fin. */
    private void startUpdate(String url) {
        try {
            if (Build.VERSION.SDK_INT >= 26 && !getPackageManager().canRequestPackageInstalls()) {
                // Une seule fois : autoriser CETTE application à installer des mises à jour.
                Toast.makeText(this, "Autorisez « Le Cayenne Étiquettes » à installer des applications, puis touchez « Mettre à jour » de nouveau.", Toast.LENGTH_LONG).show();
                startActivity(new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + getPackageName())));
                return;
            }
            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            DownloadManager.Request req = new DownloadManager.Request(Uri.parse(url));
            req.setMimeType("application/vnd.android.package-archive");
            req.setTitle("Le Cayenne Étiquettes — mise à jour");
            req.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            req.setDestinationInExternalFilesDir(this, android.os.Environment.DIRECTORY_DOWNLOADS, "LeCayenne-Etiquettes-update.apk");
            updateDownloadId = dm.enqueue(req);
            Toast.makeText(this, "Téléchargement de la mise à jour…", Toast.LENGTH_SHORT).show();
        } catch (Exception e) {
            // Dernier recours : le navigateur télécharge, l'utilisateur ouvre le fichier.
            try { startActivity(new Intent(Intent.ACTION_VIEW, Uri.parse(url))); } catch (Exception ignored) { /* rien pour l'ouvrir */ }
        }
    }

    private void registerUpdateReceiver() {
        updateReceiver = new BroadcastReceiver() {
            @Override
            public void onReceive(Context context, Intent intent) {
                long id = intent.getLongExtra(DownloadManager.EXTRA_DOWNLOAD_ID, -1);
                if (id == -1 || id != updateDownloadId) return;
                updateDownloadId = -1;
                try {
                    DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                    Uri apk = dm.getUriForDownloadedFile(id);
                    if (apk == null) { Toast.makeText(MainActivity.this, "Téléchargement impossible, réessayez.", Toast.LENGTH_LONG).show(); return; }
                    Intent install = new Intent(Intent.ACTION_VIEW);
                    install.setDataAndType(apk, "application/vnd.android.package-archive");
                    install.addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION | Intent.FLAG_ACTIVITY_NEW_TASK);
                    startActivity(install);
                } catch (Exception e) {
                    Toast.makeText(MainActivity.this, "Installation impossible : ouvrez le fichier dans Téléchargements.", Toast.LENGTH_LONG).show();
                }
            }
        };
        IntentFilter f = new IntentFilter(DownloadManager.ACTION_DOWNLOAD_COMPLETE);
        if (Build.VERSION.SDK_INT >= 33) registerReceiver(updateReceiver, f, Context.RECEIVER_EXPORTED);
        else registerReceiver(updateReceiver, f);
    }

    /**
     * Relaie la demande de micro de la page : uniquement pour NOTRE site (même hôte que l'adresse de l'application) et uniquement
     * le micro (jamais la caméra ni la position). Puis demande la permission d'exécution Android « Micro » si besoin.
     */
    private void handleWebPermission(PermissionRequest request, String host) {
        boolean notreSite = host != null && request.getOrigin() != null && host.equalsIgnoreCase(request.getOrigin().getHost());
        boolean veutLeMicro = false;
        for (String r : request.getResources()) {
            if (PermissionRequest.RESOURCE_AUDIO_CAPTURE.equals(r)) veutLeMicro = true;
        }
        if (!notreSite || !veutLeMicro) {
            request.deny();
            return;
        }
        if (checkSelfPermission(Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED) {
            request.grant(new String[]{PermissionRequest.RESOURCE_AUDIO_CAPTURE});
            return;
        }
        if (pendingMicRequest != null) pendingMicRequest.deny(); // une seule demande à la fois
        pendingMicRequest = request;
        requestPermissions(new String[]{Manifest.permission.RECORD_AUDIO}, REQ_MIC);
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (requestCode == REQ_BT && bridge != null) bridge.onBluetoothPermissionResult();
        if (requestCode == REQ_MIC && pendingMicRequest != null) {
            PermissionRequest req = pendingMicRequest;
            pendingMicRequest = null;
            if (grantResults.length > 0 && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                req.grant(new String[]{PermissionRequest.RESOURCE_AUDIO_CAPTURE});
            } else {
                req.deny();
            }
        }
    }

    @Override
    protected void onPause() {
        android.webkit.CookieManager.getInstance().flush(); // la session survit à une fermeture brutale
        super.onPause();
    }

    private String startUrl() {
        // L'activité est exportée : une adresse reçue d'une autre application recevrait le pont `LabelNative`. Seule la version
        // de test (debug) l'accepte.
        String fromIntent = BuildConfig.DEBUG && getIntent() != null ? getIntent().getStringExtra("url") : null;
        return fromIntent != null && !fromIntent.isEmpty() ? fromIntent : BuildConfig.START_URL;
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        if (web != null) web.saveState(out);
    }

    @Override
    public void onBackPressed() {
        if (web != null && web.canGoBack()) web.goBack();
        else moveTaskToBack(true); // la tablette garde l'écran ouvert : on ne ferme pas l'application
    }

    @Override
    protected void onDestroy() {
        if (updateReceiver != null) { try { unregisterReceiver(updateReceiver); } catch (Exception ignored) { /* déjà retiré */ } updateReceiver = null; }
        if (pendingMicRequest != null) { pendingMicRequest.deny(); pendingMicRequest = null; }
        if (bridge != null) bridge.shutdown();
        if (web != null) {
            ((FrameLayout) web.getParent()).removeView(web);
            web.destroy();
        }
        super.onDestroy();
    }

    @Override
    public void onWindowFocusChanged(boolean hasFocus) {
        super.onWindowFocusChanged(hasFocus);
        if (hasFocus && web != null) web.setSystemUiVisibility(View.SYSTEM_UI_FLAG_VISIBLE);
    }
}
