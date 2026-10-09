package sbs.zcloudpro.zmusic;

import android.content.Context;
import android.view.View;
import android.webkit.WebView;

/**
 * WebView que pode "fingir" que continua na tela quando o app vai para o segundo plano.
 * Com isso a página (e o player do YouTube dentro dela) não recebe o aviso de que ficou escondida
 * e a música segue tocando com a tela apagada. Só fica ligado enquanto há música carregada
 * e o administrador permitiu (Painel › App Android).
 */
public class KeepAliveWebView extends WebView {

    private boolean keep = false;
    private int realWindowVisibility = View.VISIBLE;

    public KeepAliveWebView(Context context) {
        super(context);
    }

    void setKeepAlive(boolean k) {
        if (k == keep) return;
        keep = k;
        // reaplica o estado real (ou o "visível" fingido) na hora
        super.onWindowVisibilityChanged(k ? View.VISIBLE : realWindowVisibility);
    }

    boolean isKeepAlive() {
        return keep;
    }

    @Override
    protected void onWindowVisibilityChanged(int visibility) {
        realWindowVisibility = visibility;
        super.onWindowVisibilityChanged(keep ? View.VISIBLE : visibility);
    }
}
