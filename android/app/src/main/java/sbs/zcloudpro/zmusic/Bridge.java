package sbs.zcloudpro.zmusic;

import org.json.JSONObject;

/**
 * Mensagens do site para o app (window.zMusicApp.postMessage(JSON)).
 * Só o próprio site (mesma origem, quadro principal) consegue enviar — o player do YouTube não.
 */
final class Bridge {
    private Bridge() {}

    static void handle(MainActivity a, String raw) {
        try {
            JSONObject m = new JSONObject(raw);
            switch (m.optString("t")) {
                case "media":
                    // bg = o admin liberou tocar o YouTube com o app em segundo plano
                    a.setKeepAlive(m.optBoolean("has") && m.optBoolean("bg"));
                    PlaybackService.update(a, m);
                    break;
                case "share":
                    a.share(m.optString("text"), m.optString("url"));
                    break;
                case "copy":
                    a.copy(m.optString("text"));
                    break;
                case "open":
                    a.openExternal(m.optString("url"));
                    break;
                default:
                    break;
            }
        } catch (Exception ignored) {
            // mensagem inválida: ignora
        }
    }
}
