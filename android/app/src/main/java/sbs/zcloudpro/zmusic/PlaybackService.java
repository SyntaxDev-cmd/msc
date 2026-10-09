package sbs.zcloudpro.zmusic;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.content.pm.ServiceInfo;
import android.graphics.Bitmap;
import android.graphics.BitmapFactory;
import android.net.wifi.WifiManager;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.PowerManager;
import android.os.SystemClock;
import android.support.v4.media.MediaMetadataCompat;
import android.support.v4.media.session.MediaSessionCompat;
import android.support.v4.media.session.PlaybackStateCompat;
import android.webkit.CookieManager;

import androidx.core.app.NotificationCompat;
import androidx.core.app.ServiceCompat;
import androidx.core.content.ContextCompat;
import androidx.media.app.NotificationCompat.MediaStyle;

import org.json.JSONObject;

import java.io.InputStream;
import java.net.HttpURLConnection;
import java.net.URL;

/**
 * Mantém a música do servidor tocando com o app em segundo plano / tela apagada:
 * serviço em primeiro plano (tipo mediaPlayback) + notificação com controles + tela de bloqueio.
 * Quem toca de verdade é o player do site, dentro da WebView; aqui só espelhamos o estado
 * e repassamos os botões (play/pausa/próxima/anterior/arrastar) para ele.
 *
 * Músicas tocadas pelo player do YouTube NÃO usam este serviço (os termos do YouTube não
 * permitem tocar em segundo plano) — o site pausa essas músicas quando o app vai para o fundo.
 */
public class PlaybackService extends Service {

    private static final String CHANNEL = "playback";
    private static final int NOTIF_ID = 7;
    private static final String ACT_STATE = "state", ACT_TOGGLE = "toggle", ACT_NEXT = "next", ACT_PREV = "prev", ACT_STOP = "stop";
    private static final long IDLE_STOP_MS = 15 * 60 * 1000L; // pausado há 15 min: encerra a notificação

    private static PlaybackService instance;
    private static JSONObject pending;
    private static volatile boolean playing;

    private MediaSessionCompat session;
    private PowerManager.WakeLock wake;
    private WifiManager.WifiLock wifi;
    private final Handler h = new Handler(Looper.getMainLooper());
    private JSONObject state = new JSONObject();
    private String artUrl = "";
    private Bitmap art;
    private final Runnable idleStop = this::stopSelf;

    /* ===================== chamado pela ponte ===================== */
    static boolean isPlaying() {
        return playing;
    }

    static void update(Context ctx, JSONObject s) {
        boolean has = s.optBoolean("has", false);
        boolean remote = s.optBoolean("remote", false);
        if (!has || remote) {
            playing = false;
            stop(ctx);
            return;
        }
        if (instance != null) {
            instance.apply(s);
            return;
        }
        if (!s.optBoolean("playing", false)) return; // só sobe a notificação quando começa a tocar
        pending = s;
        try {
            Intent i = new Intent(ctx, PlaybackService.class).setAction(ACT_STATE);
            ContextCompat.startForegroundService(ctx, i);
        } catch (Exception ignored) {
            // Android 12+ não deixa iniciar do fundo em alguns casos; na próxima ação do usuário tentamos de novo
        }
    }

    static void stop(Context ctx) {
        if (instance != null) instance.stopSelf();
    }

    /* ===================== ciclo de vida ===================== */
    @Override
    public void onCreate() {
        super.onCreate();
        instance = this;
        createChannel();
        session = new MediaSessionCompat(this, "zMusic");
        session.setCallback(new MediaSessionCompat.Callback() {
            @Override public void onPlay() { js("Sonora.player.play()"); }
            @Override public void onPause() { js("Sonora.player.pause()"); }
            @Override public void onSkipToNext() { js("Sonora.player.next()"); }
            @Override public void onSkipToPrevious() { js("Sonora.player.prev()"); }
            @Override public void onStop() { js("Sonora.player.pause()"); }
            @Override public void onSeekTo(long ms) { js("Sonora.player.seek(" + (ms / 1000.0) + ")"); }
        });
        Intent open = new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP);
        session.setSessionActivity(PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT));
        session.setActive(true);

        PowerManager pm = (PowerManager) getSystemService(POWER_SERVICE);
        wake = pm.newWakeLock(PowerManager.PARTIAL_WAKE_LOCK, "zMusic:playback");
        wake.setReferenceCounted(false);
        WifiManager wm = (WifiManager) getApplicationContext().getSystemService(WIFI_SERVICE);
        if (wm != null) {
            wifi = wm.createWifiLock(Build.VERSION.SDK_INT >= 29 ? WifiManager.WIFI_MODE_FULL_HIGH_PERF : WifiManager.WIFI_MODE_FULL, "zMusic:stream");
            wifi.setReferenceCounted(false);
        }
    }

    @Override
    public int onStartCommand(Intent intent, int flags, int startId) {
        // Precisa virar primeiro plano logo de cara (regra do Android)
        goForeground();
        String a = intent != null ? intent.getAction() : null;
        if (ACT_STATE.equals(a) && pending != null) {
            apply(pending);
            pending = null;
        } else if (ACT_TOGGLE.equals(a)) {
            js("Sonora.player.toggle()");
        } else if (ACT_NEXT.equals(a)) {
            js("Sonora.player.next()");
        } else if (ACT_PREV.equals(a)) {
            js("Sonora.player.prev()");
        } else if (ACT_STOP.equals(a)) {
            js("Sonora.player.pause()");
            stopSelf();
        }
        return START_NOT_STICKY;
    }

    @Override
    public void onTaskRemoved(Intent rootIntent) {
        // App fechado pela lista de recentes: a WebView morre junto, então encerramos
        stopSelf();
    }

    @Override
    public void onDestroy() {
        instance = null;
        playing = false;
        h.removeCallbacksAndMessages(null);
        if (wake != null && wake.isHeld()) wake.release();
        if (wifi != null && wifi.isHeld()) wifi.release();
        session.setActive(false);
        session.release();
        ServiceCompat.stopForeground(this, ServiceCompat.STOP_FOREGROUND_REMOVE);
        super.onDestroy();
    }

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    /* ===================== estado → sessão + notificação ===================== */
    private void apply(JSONObject s) {
        state = s;
        playing = s.optBoolean("playing", false);
        long pos = Math.round(s.optDouble("pos", 0) * 1000);
        long dur = Math.round(s.optDouble("dur", 0) * 1000);

        MediaMetadataCompat.Builder md = new MediaMetadataCompat.Builder()
                .putString(MediaMetadataCompat.METADATA_KEY_TITLE, s.optString("title"))
                .putString(MediaMetadataCompat.METADATA_KEY_ARTIST, s.optString("artist"))
                .putString(MediaMetadataCompat.METADATA_KEY_ALBUM, s.optString("album"));
        if (dur > 0) md.putLong(MediaMetadataCompat.METADATA_KEY_DURATION, dur);
        String cover = s.optString("cover");
        if (cover.equals(artUrl) && art != null) md.putBitmap(MediaMetadataCompat.METADATA_KEY_ALBUM_ART, art);
        session.setMetadata(md.build());

        long actions = PlaybackStateCompat.ACTION_PLAY | PlaybackStateCompat.ACTION_PAUSE | PlaybackStateCompat.ACTION_PLAY_PAUSE
                | PlaybackStateCompat.ACTION_SKIP_TO_NEXT | PlaybackStateCompat.ACTION_SKIP_TO_PREVIOUS
                | PlaybackStateCompat.ACTION_STOP | PlaybackStateCompat.ACTION_SEEK_TO;
        session.setPlaybackState(new PlaybackStateCompat.Builder()
                .setActions(actions)
                .setState(playing ? PlaybackStateCompat.STATE_PLAYING : PlaybackStateCompat.STATE_PAUSED, pos, playing ? 1f : 0f, SystemClock.elapsedRealtime())
                .build());

        if (playing) {
            h.removeCallbacks(idleStop);
            if (!wake.isHeld()) wake.acquire(6 * 60 * 60 * 1000L);
            if (wifi != null && !wifi.isHeld()) wifi.acquire();
        } else {
            h.removeCallbacks(idleStop);
            h.postDelayed(idleStop, IDLE_STOP_MS);
            if (wake.isHeld()) wake.release();
            if (wifi != null && wifi.isHeld()) wifi.release();
        }
        notifyNow();
        if (!cover.isEmpty() && !cover.equals(artUrl)) loadArt(cover);
    }

    private void goForeground() {
        Notification n = build();
        int type = Build.VERSION.SDK_INT >= 29 ? ServiceInfo.FOREGROUND_SERVICE_TYPE_MEDIA_PLAYBACK : 0;
        try {
            ServiceCompat.startForeground(this, NOTIF_ID, n, type);
        } catch (Exception e) {
            stopSelf();
        }
    }

    private void notifyNow() {
        NotificationManager nm = (NotificationManager) getSystemService(NOTIFICATION_SERVICE);
        try {
            nm.notify(NOTIF_ID, build());
        } catch (SecurityException ignored) {
            // sem permissão de notificação: a música continua mesmo assim
        }
    }

    private Notification build() {
        Intent open = new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_SINGLE_TOP);
        PendingIntent content = PendingIntent.getActivity(this, 0, open, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
        NotificationCompat.Builder b = new NotificationCompat.Builder(this, CHANNEL)
                .setSmallIcon(R.drawable.ic_stat_music)
                .setContentTitle(state.optString("title", getString(R.string.app_name)))
                .setContentText(state.optString("artist"))
                .setSubText(state.optString("album"))
                .setContentIntent(content)
                .setDeleteIntent(action(ACT_STOP, 4))
                .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
                .setCategory(NotificationCompat.CATEGORY_TRANSPORT)
                .setPriority(NotificationCompat.PRIORITY_LOW)
                .setShowWhen(false)
                .setOnlyAlertOnce(true)
                .setOngoing(playing)
                .setColor(ContextCompat.getColor(this, R.color.accent))
                .addAction(R.drawable.ic_prev, getString(R.string.prev), action(ACT_PREV, 1))
                .addAction(playing ? R.drawable.ic_pause : R.drawable.ic_play, getString(playing ? R.string.pause : R.string.play), action(ACT_TOGGLE, 2))
                .addAction(R.drawable.ic_next, getString(R.string.next), action(ACT_NEXT, 3))
                .setStyle(new MediaStyle().setMediaSession(session.getSessionToken()).setShowActionsInCompactView(0, 1, 2));
        if (art != null) b.setLargeIcon(art);
        return b.build();
    }

    private PendingIntent action(String act, int code) {
        Intent i = new Intent(this, PlaybackService.class).setAction(act);
        return PendingIntent.getService(this, code, i, PendingIntent.FLAG_IMMUTABLE | PendingIntent.FLAG_UPDATE_CURRENT);
    }

    private void createChannel() {
        if (Build.VERSION.SDK_INT < 26) return;
        NotificationChannel ch = new NotificationChannel(CHANNEL, getString(R.string.channel_playback), NotificationManager.IMPORTANCE_LOW);
        ch.setDescription(getString(R.string.channel_playback_desc));
        ch.setShowBadge(false);
        ch.setLockscreenVisibility(Notification.VISIBILITY_PUBLIC);
        ((NotificationManager) getSystemService(NOTIFICATION_SERVICE)).createNotificationChannel(ch);
    }

    /** Capa da música (com o cookie de login, porque as capas do acervo são protegidas) */
    private void loadArt(String url) {
        artUrl = url;
        art = null;
        new Thread(() -> {
            Bitmap bmp = null;
            try {
                HttpURLConnection c = (HttpURLConnection) new URL(url).openConnection();
                c.setConnectTimeout(8000);
                c.setReadTimeout(10000);
                String cookie = CookieManager.getInstance().getCookie(url);
                if (cookie != null) c.setRequestProperty("Cookie", cookie);
                try (InputStream in = c.getInputStream()) {
                    BitmapFactory.Options o = new BitmapFactory.Options();
                    o.inPreferredConfig = Bitmap.Config.RGB_565;
                    bmp = BitmapFactory.decodeStream(in, null, o);
                }
                if (bmp != null && (bmp.getWidth() > 512 || bmp.getHeight() > 512)) {
                    float k = 512f / Math.max(bmp.getWidth(), bmp.getHeight());
                    bmp = Bitmap.createScaledBitmap(bmp, Math.round(bmp.getWidth() * k), Math.round(bmp.getHeight() * k), true);
                }
            } catch (Exception ignored) {
                // sem capa
            }
            final Bitmap result = bmp;
            h.post(() -> {
                if (!url.equals(artUrl) || instance != this) return;
                art = result;
                apply(state);
            });
        }).start();
    }

    private void js(String code) {
        MainActivity a = MainActivity.current;
        if (a == null) {
            stopSelf();
            return;
        }
        a.runJs("try{" + code + "}catch(e){}");
    }
}
