# zMusic para Android (WebView)

App Android que abre o site **https://zmusic.zcloudpro.sbs** em tela cheia.

**Atualizou o site → o app atualiza junto.** Telas, player, painel, cores, logo: tudo vem do servidor.
Só é preciso gerar um APK novo se mudar a parte nativa (este diretório).

## O que o app faz além do site
- **Toca em segundo plano / tela apagada** as músicas do acervo do servidor, com notificação
  (anterior · play/pausa · próxima · barra de posição), controles na tela de bloqueio, fone Bluetooth e relógio.
- **Salvar ao ouvir**: música tocada pelo player do YouTube por mais de 30 s vai sozinha para o acervo e, quando fica pronta,
  o player troca para o arquivo do servidor no mesmo ponto — daí em diante ela toca em segundo plano também.
- **YouTube em segundo plano (1.1)**: com "YouTube em segundo plano no app" ligado no painel, o app mantém a página
  "aberta" mesmo minimizado (a WebView continua se dizendo visível), então o player do YouTube segue tocando com a tela
  apagada, com notificação e controles. Desligado (ou no modo loja), o YouTube pausa no fundo e a fila pula para músicas do servidor.
- Botão voltar inteligente (fecha "tocando agora", janelas e menus; com música tocando, sair só manda o app para o fundo).
- Compartilhar/copiar nativos (link de indicação, Pix copia-e-cola), seletor de arquivos (logo), downloads pelo
  gerenciador do Android, vídeo em tela cheia, tela "sem conexão" com "tentar de novo" (as músicas salvas offline continuam tocando).
- Abre links do site direto no app (verificado por `/.well-known/assetlinks.json`).
- Avisa quando há um APK novo (lê `/app.json` do site — configure em **Painel › Marca e config. › 📱 App Android**).

## Políticas da Play Store
- `targetSdk 36`, só HTTPS, sem anúncios, sem rastreadores, permissões mínimas
  (internet, notificações, serviço em primeiro plano do tipo **mediaPlayback**, wake lock).
- Política de privacidade: `https://SEU-SITE/privacy.php`. Exclusão de conta: **Minha conta › Excluir minha conta**.
- **Modo loja** (Painel › 📱 App Android): esconde, só dentro do app, os botões de baixar do YouTube —
  a Play Store não aceita apps que baixam do YouTube. Ligue antes de enviar para a loja. No site continua tudo igual.
- Para a loja é preciso um **AAB** (`./gradlew bundleRelease`); para instalar direto, o **APK**.

## Compilar
Sem instalar nada: cada push que muda `android/` roda o GitHub Actions (`.github/workflows/android-apk.yml`).
- Sem segredos configurados → gera o APK **sem assinatura** em `android/dist/` (no próprio branch), junto com o `apksigner.jar`.
  Assine no seu PC (Java 17+):
  ```
  java -jar apksigner.jar sign --ks zmusic-upload.jks --ks-key-alias zmusic --out zmusic.apk zmusic-unsigned.apk
  ```
- Com os segredos `ANDROID_KEYSTORE_BASE64` (o .jks em base64), `ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_ALIAS` (zmusic)
  e `ANDROID_KEY_PASSWORD` em *Settings › Secrets and variables › Actions* → gera o APK **já assinado** em *Artifacts*.

No PC com Android Studio: abra a pasta `android/`, crie `keystore.properties`:
```
storeFile=zmusic-upload.jks
storePassword=SUA_SENHA
keyAlias=zmusic
keyPassword=SUA_SENHA
```
e rode `./gradlew assembleRelease` → `app/build/outputs/apk/release/app-release.apk`.

## Mudar site/nome (white-label)
Em `gradle.properties`: `zmusic.siteUrl` e `zmusic.appName`. Para outro app na loja, troque também o `applicationId`
em `app/build.gradle`, as cores em `res/values/colors.xml` e o ícone em `res/drawable/ic_launcher_*.xml`.

## Lançar uma versão nativa nova
1. Aumente `versionCode` (e `versionName`) em `app/build.gradle`.
2. Compile e assine **sempre com a mesma chave** (sem ela o Android não deixa atualizar o app instalado).
3. Suba o APK para `download/zmusic.apk` no site e, no painel, ponha o novo `versionCode` em "Versão mais nova".
