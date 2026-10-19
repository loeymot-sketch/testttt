package fr.lecayenne.etiquettes;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
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
                // Wi‑Fi coupé au lancement ou pendant une coupure : la page d'erreur de Chrome ne se relance pas toute seule.
                if (request.isForMainFrame()) view.postDelayed(() -> view.loadUrl(startUrl), 10000);
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
