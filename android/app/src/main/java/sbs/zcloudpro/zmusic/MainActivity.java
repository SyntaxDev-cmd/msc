package sbs.zcloudpro.zmusic;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.AlertDialog;
import android.app.DownloadManager;
import android.content.ActivityNotFoundException;
import android.content.ClipData;
import android.content.ClipboardManager;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.graphics.Color;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.view.Gravity;
import android.view.View;
import android.view.ViewGroup;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Button;
import android.widget.FrameLayout;
import android.widget.LinearLayout;
import android.widget.ProgressBar;
import android.widget.TextView;
import android.widget.Toast;

import androidx.activity.ComponentActivity;
import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.core.content.ContextCompat;
import androidx.core.graphics.Insets;
import androidx.core.splashscreen.SplashScreen;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.core.view.WindowInsetsControllerCompat;
import androidx.webkit.WebViewCompat;
import androidx.webkit.WebViewFeature;

import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.Collections;

/**
 * zMusic — o app é uma "janela" para o site. Tudo (telas, player, painel) vem do servidor:
 * atualizou o site, o app atualiza junto. A parte nativa só cuida do que o site não consegue
 * sozinho: tocar em segundo plano com notificação, voltar, compartilhar, baixar arquivos.
 */
public class MainActivity extends ComponentActivity {

    static MainActivity current;

    private KeepAliveWebView web;
    private FrameLayout root;
    private View offlineView;
    private ProgressBar progress;
    private View customView;
    private WebChromeClient.CustomViewCallback customViewCallback;
    private ValueCallback<Uri[]> fileCallback;
    private ActivityResultLauncher<Intent> filePicker;
    private boolean pageReady = false;
    private long lastBack = 0;
    private final Handler ui = new Handler(Looper.getMainLooper());

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        SplashScreen splash = SplashScreen.installSplashScreen(this);
        super.onCreate(savedInstanceState);
        splash.setKeepOnScreenCondition(() -> !pageReady);
        ui.postDelayed(() -> pageReady = true, 4000); // nunca prende na abertura
        current = this;

        WindowCompat.setDecorFitsSystemWindows(getWindow(), false);
        root = new FrameLayout(this);
        root.setBackgroundColor(ContextCompat.getColor(this, R.color.bg));
        setContentView(root);
        ViewCompat.setOnApplyWindowInsetsListener(root, (v, insets) -> {
            if (customView != null) {
                v.setPadding(0, 0, 0, 0);
                return insets;
            }
            Insets bars = insets.getInsets(WindowInsetsCompat.Type.systemBars() | WindowInsetsCompat.Type.displayCutout());
            Insets ime = insets.getInsets(WindowInsetsCompat.Type.ime());
            v.setPadding(bars.left, bars.top, bars.right, Math.max(bars.bottom, ime.bottom));
            return WindowInsetsCompat.CONSUMED;
        });
        WindowInsetsControllerCompat wic = WindowCompat.getInsetsController(getWindow(), root);
        wic.setAppearanceLightStatusBars(false);
        wic.setAppearanceLightNavigationBars(false);

        web = new KeepAliveWebView(this);
        web.setBackgroundColor(ContextCompat.getColor(this, R.color.bg));
        root.addView(web, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));

        progress = new ProgressBar(this, null, android.R.attr.progressBarStyleHorizontal);
        progress.setIndeterminate(false);
        progress.setMax(100);
        progress.setProgressTintList(android.content.res.ColorStateList.valueOf(ContextCompat.getColor(this, R.color.accent)));
        FrameLayout.LayoutParams pl = new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(3), Gravity.TOP);
        root.addView(progress, pl);
        progress.setVisibility(View.GONE);

        filePicker = registerForActivityResult(new ActivityResultContracts.StartActivityForResult(), result -> {
            if (fileCallback == null) return;
            fileCallback.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(result.getResultCode(), result.getData()));
            fileCallback = null;
        });

        setupWebView();
        setupBack();
        askNotificationPermission();

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState);
        } else {
            web.loadUrl(startUrl(getIntent()));
        }
        UpdateChecker.check(this);
    }

    private String startUrl(Intent intent) {
        Uri data = intent != null ? intent.getData() : null;
        if (data != null && isOwnHost(data)) return data.toString();
        return BuildConfig.SITE_URL;
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        Uri data = intent.getData();
        if (data != null && isOwnHost(data)) web.loadUrl(data.toString());
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void setupWebView() {
        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setDatabaseEnabled(true);
        s.setMediaPlaybackRequiresUserGesture(false);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);
        s.setLoadWithOverviewMode(true);
        s.setUseWideViewPort(true);
        s.setSupportZoom(false);
        s.setBuiltInZoomControls(false);
        s.setDisplayZoomControls(false);
        s.setSupportMultipleWindows(false);
        s.setJavaScriptCanOpenWindowsAutomatically(false);
        s.setCacheMode(WebSettings.LOAD_DEFAULT);
        s.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        // O site reconhece o app por aqui (mostra recursos do app e esconde o "instale o app")
        s.setUserAgentString(s.getUserAgentString() + " zMusicApp/" + BuildConfig.VERSION_NAME + " (" + BuildConfig.VERSION_CODE + ")");

        CookieManager cm = CookieManager.getInstance();
        cm.setAcceptCookie(true);
        cm.setAcceptThirdPartyCookies(web, true); // player oficial do YouTube

        // Ponte segura: só o nosso site (mesma origem) enxerga window.zMusicApp
        if (WebViewFeature.isFeatureSupported(WebViewFeature.WEB_MESSAGE_LISTENER)) {
            String origin = Uri.parse(BuildConfig.SITE_URL).getScheme() + "://" + BuildConfig.SITE_HOST;
            WebViewCompat.addWebMessageListener(web, "zMusicApp", Collections.singleton(origin),
                    (view, message, sourceOrigin, isMainFrame, replyProxy) -> {
                        if (!isMainFrame || message.getData() == null) return;
                        Bridge.handle(this, message.getData());
                    });
        }

        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                if (!request.isForMainFrame()) return false;
                Uri u = request.getUrl();
                if (isOwnHost(u)) return false;
                openExternal(u.toString());
                return true;
            }

            @Override
            public void onPageStarted(WebView view, String url, Bitmap favicon) {
                hideOffline();
            }

            @Override
            public void onPageFinished(WebView view, String url) {
                pageReady = true;
                CookieManager.getInstance().flush();
            }

            @Override
            public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
                if (request.isForMainFrame()) showOffline();
            }
        });

        web.setWebChromeClient(new WebChromeClient() {
            @Override
            public void onProgressChanged(WebView view, int p) {
                progress.setProgress(p);
                progress.setVisibility(p < 100 ? View.VISIBLE : View.GONE);
            }

            @Override
            public boolean onShowFileChooser(WebView view, ValueCallback<Uri[]> callback, FileChooserParams params) {
                if (fileCallback != null) fileCallback.onReceiveValue(null);
                fileCallback = callback;
                try {
                    filePicker.launch(params.createIntent());
                } catch (ActivityNotFoundException e) {
                    fileCallback = null;
                    return false;
                }
                return true;
            }

            @Override
            public void onShowCustomView(View view, CustomViewCallback callback) {
                if (customView != null) {
                    callback.onCustomViewHidden();
                    return;
                }
                customView = view;
                customViewCallback = callback;
                root.addView(view, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
                WindowInsetsControllerCompat c = WindowCompat.getInsetsController(getWindow(), root);
                c.hide(WindowInsetsCompat.Type.systemBars());
                c.setSystemBarsBehavior(WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
                ViewCompat.requestApplyInsets(root);
            }

            @Override
            public void onHideCustomView() {
                exitFullscreen();
            }
        });

        web.setDownloadListener((url, userAgent, contentDisposition, mimeType, length) -> download(url, userAgent, contentDisposition, mimeType));
    }

    private void exitFullscreen() {
        if (customView == null) return;
        root.removeView(customView);
        customView = null;
        if (customViewCallback != null) customViewCallback.onCustomViewHidden();
        customViewCallback = null;
        WindowCompat.getInsetsController(getWindow(), root).show(WindowInsetsCompat.Type.systemBars());
        ViewCompat.requestApplyInsets(root);
    }

    private void setupBack() {
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                if (customView != null) {
                    exitFullscreen();
                    return;
                }
                if (offlineView != null) {
                    leaveApp();
                    return;
                }
                // O site fecha o que estiver aberto (tela "tocando agora", janelas, menu); senão volta a página
                web.evaluateJavascript("(function(){try{return !!(window.Sonora&&Sonora.back&&Sonora.back())}catch(e){return false}})()", handled -> {
                    if ("true".equals(handled)) return;
                    if (web.canGoBack()) {
                        web.goBack();
                        return;
                    }
                    leaveApp();
                });
            }
        });
    }

    /** Sair sem parar a música: se estiver tocando, só manda o app para o fundo */
    private void leaveApp() {
        if (PlaybackService.isPlaying()) {
            moveTaskToBack(true);
            return;
        }
        long now = System.currentTimeMillis();
        if (now - lastBack < 2000) {
            moveTaskToBack(true);
        } else {
            lastBack = now;
            Toast.makeText(this, R.string.press_again, Toast.LENGTH_SHORT).show();
        }
    }

    boolean isOwnHost(Uri u) {
        return u != null && "https".equalsIgnoreCase(u.getScheme()) && BuildConfig.SITE_HOST.equalsIgnoreCase(u.getHost());
    }

    void openExternal(String url) {
        try {
            Intent i;
            if (url.startsWith("intent:")) {
                i = Intent.parseUri(url, Intent.URI_INTENT_SCHEME);
                i.addCategory(Intent.CATEGORY_BROWSABLE);
                i.setComponent(null);
                i.setSelector(null);
            } else {
                i = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
            }
            i.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(i);
        } catch (Exception e) {
            Toast.makeText(this, "Não há app para abrir este link", Toast.LENGTH_SHORT).show();
        }
    }

    private void download(String url, String userAgent, String contentDisposition, String mimeType) {
        if (!url.startsWith("http")) {
            Toast.makeText(this, "Este arquivo não pode ser baixado pelo app", Toast.LENGTH_SHORT).show();
            return;
        }
        try {
            String name = URLUtil.guessFileName(url, contentDisposition, mimeType);
            DownloadManager.Request r = new DownloadManager.Request(Uri.parse(url));
            r.setMimeType(mimeType);
            String cookie = CookieManager.getInstance().getCookie(url);
            if (cookie != null) r.addRequestHeader("Cookie", cookie);
            r.addRequestHeader("User-Agent", userAgent);
            r.setTitle(name);
            r.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
            r.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name);
            DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
            dm.enqueue(r);
            Toast.makeText(this, R.string.download_started, Toast.LENGTH_LONG).show();
        } catch (Exception e) {
            openExternal(url);
        }
    }

    /* ---------- Tela "sem conexão" ---------- */
    private void showOffline() {
        pageReady = true;
        if (offlineView != null) return;
        LinearLayout box = new LinearLayout(this);
        box.setOrientation(LinearLayout.VERTICAL);
        box.setGravity(Gravity.CENTER);
        box.setPadding(dp(32), dp(32), dp(32), dp(32));
        box.setBackgroundColor(ContextCompat.getColor(this, R.color.bg));
        box.setClickable(true);

        android.widget.ImageView logo = new android.widget.ImageView(this);
        logo.setImageResource(R.drawable.ic_launcher_foreground);
        box.addView(logo, new LinearLayout.LayoutParams(dp(140), dp(140)));

        TextView t = new TextView(this);
        t.setText(R.string.offline_title);
        t.setTextColor(ContextCompat.getColor(this, R.color.text));
        t.setTextSize(22);
        t.setGravity(Gravity.CENTER);
        t.setTypeface(android.graphics.Typeface.DEFAULT_BOLD);
        box.addView(t);

        TextView m = new TextView(this);
        m.setText(R.string.offline_msg);
        m.setTextColor(ContextCompat.getColor(this, R.color.muted));
        m.setTextSize(15);
        m.setGravity(Gravity.CENTER);
        m.setPadding(0, dp(10), 0, dp(24));
        box.addView(m);

        Button b = new Button(this);
        b.setText(R.string.retry);
        b.setAllCaps(false);
        b.setTextColor(Color.WHITE);
        android.graphics.drawable.GradientDrawable bg = new android.graphics.drawable.GradientDrawable(
                android.graphics.drawable.GradientDrawable.Orientation.LEFT_RIGHT,
                new int[]{ContextCompat.getColor(this, R.color.accent), ContextCompat.getColor(this, R.color.accent2)});
        bg.setCornerRadius(dp(24));
        b.setBackground(bg);
        b.setPadding(dp(28), 0, dp(28), 0);
        b.setOnClickListener(v -> {
            hideOffline();
            String u = web.getUrl();
            if (u == null || !u.startsWith("http")) web.loadUrl(BuildConfig.SITE_URL);
            else web.reload();
        });
        box.addView(b, new LinearLayout.LayoutParams(ViewGroup.LayoutParams.WRAP_CONTENT, dp(48)));

        offlineView = box;
        root.addView(box, new FrameLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.MATCH_PARENT));
    }

    private void hideOffline() {
        if (offlineView == null) return;
        root.removeView(offlineView);
        offlineView = null;
    }

    /* ---------- Chamadas do serviço de mídia/notificação para o player do site ---------- */
    void runJs(String js) {
        ui.post(() -> {
            if (web != null) web.evaluateJavascript(js, null);
        });
    }

    void setKeepAlive(boolean k) {
        ui.post(() -> {
            if (web != null) web.setKeepAlive(k);
        });
    }

    void copy(String text) {
        ClipboardManager cb = (ClipboardManager) getSystemService(Context.CLIPBOARD_SERVICE);
        cb.setPrimaryClip(ClipData.newPlainText("zMusic", text));
        if (Build.VERSION.SDK_INT < 33) Toast.makeText(this, R.string.copied, Toast.LENGTH_SHORT).show();
    }

    void share(String text, String url) {
        Intent i = new Intent(Intent.ACTION_SEND);
        i.setType("text/plain");
        String body = (text == null ? "" : text) + (url != null && !url.isEmpty() ? (text == null || text.isEmpty() ? "" : "\n") + url : "");
        i.putExtra(Intent.EXTRA_TEXT, body);
        startActivity(Intent.createChooser(i, getString(R.string.share_via)));
    }

    private void askNotificationPermission() {
        if (Build.VERSION.SDK_INT >= 33
                && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            registerForActivityResult(new ActivityResultContracts.RequestPermission(), granted -> { })
                    .launch(Manifest.permission.POST_NOTIFICATIONS);
        }
    }

    int dp(int v) {
        return Math.round(v * getResources().getDisplayMetrics().density);
    }

    @Override
    protected void onSaveInstanceState(Bundle out) {
        super.onSaveInstanceState(out);
        web.saveState(out);
    }

    @Override
    protected void onStart() {
        super.onStart();
        runJs("window.Sonora&&Sonora.appVisible&&Sonora.appVisible(true)");
    }

    // Importante: NÃO chamamos web.onPause()/pauseTimers() — é isso que deixa a música tocar com a tela apagada.
    @Override
    protected void onStop() {
        super.onStop();
        runJs("window.Sonora&&Sonora.appVisible&&Sonora.appVisible(false)");
        CookieManager.getInstance().flush();
    }

    @Override
    protected void onDestroy() {
        if (current == this) current = null;
        PlaybackService.stop(this);
        if (web != null) {
            root.removeView(web);
            web.destroy();
            web = null;
        }
        super.onDestroy();
    }

    /* ---------- Aviso de versão nova da parte nativa (o resto atualiza pelo site) ---------- */
    static final class UpdateChecker {
        static void check(MainActivity a) {
            new Thread(() -> {
                try {
                    HttpURLConnection c = (HttpURLConnection) new URL(BuildConfig.SITE_URL + "app.json").openConnection();
                    c.setConnectTimeout(8000);
                    c.setReadTimeout(8000);
                    if (c.getResponseCode() != 200) return;
                    StringBuilder sb = new StringBuilder();
                    try (BufferedReader r = new BufferedReader(new InputStreamReader(c.getInputStream(), StandardCharsets.UTF_8))) {
                        String line;
                        while ((line = r.readLine()) != null) sb.append(line);
                    }
                    JSONObject j = new JSONObject(sb.toString());
                    int min = j.optInt("min_version_code", 0);
                    int latest = j.optInt("latest_version_code", 0);
                    String apk = j.optString("apk_url", "");
                    String msg = j.optString("message", "");
                    int mine = BuildConfig.VERSION_CODE;
                    if (apk.isEmpty() || latest <= mine) return;
                    boolean required = min > mine;
                    String skipKey = "skip_" + latest;
                    if (!required && a.getPreferences(MODE_PRIVATE).getBoolean(skipKey, false)) return;
                    a.ui.post(() -> {
                        if (a.isFinishing()) return;
                        AlertDialog.Builder b = new AlertDialog.Builder(a, R.style.Dialog_ZMusic)
                                .setTitle(R.string.update_title)
                                .setMessage(required ? a.getString(R.string.update_required) + (msg.isEmpty() ? "" : "\n\n" + msg)
                                        : (msg.isEmpty() ? "Há uma versão nova do app com melhorias." : msg))
                                .setPositiveButton(R.string.update_now, (d, w) -> {
                                    a.openExternal(apk);
                                    if (required) a.finish();
                                })
                                .setCancelable(!required);
                        if (!required) b.setNegativeButton(R.string.later, (d, w) ->
                                a.getPreferences(MODE_PRIVATE).edit().putBoolean(skipKey, true).apply());
                        b.show();
                    });
                } catch (Exception ignored) {
                    // sem internet ou site fora do ar: o app segue normal
                }
            }).start();
        }
    }
}
