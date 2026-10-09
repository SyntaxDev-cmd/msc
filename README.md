# 🎧 Sonora — sua biblioteca de música self-hosted (PHP, roda na Hostinger)

Você pesquisa uma **música** ou um **artista**, filtra os resultados (músicas ou vídeos), e o Sonora
**baixa, converte para MP3/Opus/M4A (leve)** e **organiza no servidor por `Gênero/Artista/Música`**.
Se você pesquisar de novo, ele **reconhece o que já tem e não baixa outra vez**. Tudo fica hospedado
na sua Hostinger, com um **player moderno** para ouvir de qualquer lugar (PC, celular, como app).

```
storage/library/
├── Sertanejo/
│   └── Marília Mendonça/
│       ├── Infiel.mp3
│       └── Infiel.jpg          ← capa em alta resolução
├── Pop/
│   └── Anitta/
│       ├── Envolver.mp3
│       └── Envolver (vídeo).mp4
└── Rock/ …
```

## ✨ Recursos

**▶ Tocar qualquer música na hora, sem baixar**
- Todo resultado de busca tem o botão **Tocar**: a música toca na hora pelo **player oficial do YouTube**
  (IFrame API) integrado ao nosso player — fila, próxima/anterior, letra e tela “tocando agora” funcionam igual.
- No catálogo do artista, **“Ouvir tudo agora”** toca a discografia inteira em sequência.
- Como quem toca é o navegador do ouvinte, **o bloqueio do IP do servidor não interfere**.
- Se o dono de um vídeo não permitir tocar fora do YouTube, o sistema troca sozinho por outra versão da mesma música.
- Baixar continua opcional: serve para guardar na biblioteca do servidor e para ouvir **offline**.
- Regra do YouTube: a janelinha do vídeo fica visível enquanto toca (no celular, o YouTube pausa com a tela
  bloqueada — para ouvir em segundo plano, baixe a música).

**🎧 Descoberta (página inicial)**
- **Em alta esta semana** e **Mais ouvidas de todos os tempos**: somam tudo o que *todos* os clientes ouvem
  (pelo servidor ou pelo YouTube — a mesma música conta junto).
- **Feito para você**: rádio do YouTube Music a partir do que cada cliente ouviu, sem repetir o que ele já ouviu.
- **Continuar ouvindo**, **Seus artistas** e **18 estilos** (Sertanejo, Funk, Pagode, Trap, Piseiro, Gospel…)
  com as mais tocadas de cada um.
- **Sugestão de artistas enquanto digita** e **“Fãs também curtem”** no catálogo do artista.
- **Reprodução automática**: quando a fila acaba, continua com músicas parecidas (liga/desliga na aba Fila).
  Também há **“Rádio desta música”** no menu ⋯.
- **Favoritar** funciona também para músicas tocadas direto do YouTube.

**Anúncios e clipe**
- Músicas do **nosso servidor** tocam pelo nosso player: **sem anúncio e sem clipe**. Marcadas com ✓ nas listas.
- Músicas tocadas direto do YouTube usam o player oficial (regra do YouTube): o cliente escolhe **com clipe** ou
  **modo música** (mini player de 200×200, o mínimo permitido; a tela cheia mostra a capa). Usamos o modo de
  privacidade (`youtube-nocookie.com`). Os anúncios desse player são controlados pelo YouTube e não podem ser
  removidos — para zerar anúncios, baixe as músicas para o servidor (cookies/API no install.php).

**Busca e download**
- **Baixa qualquer música ou vídeo que existir no YouTube** — é a fonte padrão da busca (até 50 resultados com “Carregar mais”).
- **Artista (catálogo completo)**: abre a página oficial do artista no YouTube Music e junta a lista “todas as músicas”, **todas as faixas de todos os álbuns, singles e EPs** (abertos em paralelo), a busca de músicas e os clipes do canal oficial — sem duplicar, até 800 faixas, com botão **“Baixar discografia”**.
- Outras fontes: **Catálogo oficial** (iTunes, com gênero/álbum/capa oficiais), **Artista (discografia)** — até 150 músicas do artista para baixar tudo com 1 clique — e **Músicas livres** (Jamendo). Se o catálogo não encontrar a música, a busca cai automaticamente no YouTube.
- Mesmo vídeos do YouTube ganham gênero e capa oficiais quando a música é reconhecida no catálogo; os demais vão para a pasta `Outros/Canal`.
- Escolha **Áudio** ou **Vídeo** antes de baixar.
- Filtros: sem “ao vivo”, sem covers/karaokê, até 10 min, “só o que eu não tenho”.
- **Prévia de 30 s** antes de baixar (catálogo).
- Botão **“Baixar todas (N)”** — ignora automaticamente o que já está na biblioteca.
- No catálogo, o sistema procura sozinho o **melhor áudio oficial** no YouTube (prefere canais “- Topic”/VEVO, compara duração, evita versões ao vivo/cover).

**Sem downloads duplicados** — 3 níveis de checagem: mesmo item, mesmo vídeo do YouTube, e **mesmo artista + mesmo título normalizado** (ex.: `ENVOLVER (Clipe Oficial) [4K]` de `Anitta feat. X` = `Envolver` de `Anitta`).

**Organização automática** — gênero, nome oficial do artista, álbum, ano e capa vêm da iTunes Search API. Se você apagar um arquivo pelo FTP, o sistema percebe e deixa baixar de novo.

**Player** 🎶
- Visual “glass” escuro, com **cor do tema extraída da capa** da música atual.
- **Visualizador de áudio** radial em tempo real (Web Audio API) + mini-visualizador na barra.
- **Letras sincronizadas estilo karaokê** (LRCLIB) — clique numa linha para pular para aquele trecho.
- **Equalizador** de 3 bandas com presets (Grave, Vocal, Agudo, Festa).
- **Timer para dormir** (15/30/45/60 min ou “fim desta música”) com fade-out.
- Fila, aleatório, repetir (fila/uma), favoritas, contagem de reproduções, “Mix aleatório”.
- **Controles na tela de bloqueio / fones Bluetooth** (Media Session API).
- Avançar/voltar instantâneo (streaming com HTTP Range).
- Modo vídeo na tela “Tocando agora”.
- **Instalável como app** no celular (PWA) — arraste para baixo para fechar o player.
- Atalhos: `Espaço` play/pause · `←/→` ±5 s · `↑/↓` volume · `N`/`P` próxima/anterior · `S` aleatório · `R` repetir · `L` letra · `Q` fila · `F` favoritar · `M` mudo · `/` buscar.

**Segurança** — login por usuário com hash bcrypt e bloqueio após tentativas erradas, proteção CSRF, permissões checadas no servidor em toda ação, cada cliente só acessa os arquivos da própria biblioteca, transações SQLite com trava de escrita para créditos, nenhum comando passa por shell (sem injeção), mídia e banco bloqueados para acesso direto.

## 💼 Plataforma de revenda (v2)

### Hierarquia e permissões
```
👑 Administrador ── vê e gerencia tudo, define planos, preços, marca e Mercado Pago
 └─ 💎 Revenda Master ── cria revendas e clientes, distribui créditos
     └─ 🏪 Revenda ── cria e renova clientes
         └─ 🎧 Cliente ── ouve, busca e baixa dentro dos limites do plano
```
- Cada conta **só enxerga as contas abaixo dela** (uma revenda nunca vê clientes de outra).
- **Créditos**: criar ou renovar um cliente custa os créditos do plano (ex.: Mensal = 1, Anual = 12). O admin emite créditos; masters repassam (e podem recolher) das revendas. Tudo fica registrado.
- **Limites**: máximo de clientes e de revendas por árvore, testes grátis por dia, downloads por dia, músicas na biblioteca, vídeo sim/não e offline sim/não.
- **Vencimento**: conta vencida só acessa “Minha conta” para renovar; faixas de aviso aparecem 5 dias antes e durante o teste grátis.
- **Suporte**: “Entrar como” um cliente (com botão para voltar), bloquear/desbloquear, cartão de acesso pronto para enviar no WhatsApp.
- **Log de atividades** de toda a rede (quem criou, renovou, transferiu créditos, recebeu pagamento).

### Mercado Pago
- **Pix com QR Code e copia-e-cola dentro do app** + **Checkout Pro** (cartão, boleto, saldo MP).
- Liberação **automática**: webhook + consulta ativa de reserva (o pagamento é sempre conferido direto na API do MP, nunca confiamos no corpo da notificação). Processamento **idempotente** (um pagamento nunca renova duas vezes) e confere se o valor pago bate com o cobrado.
- **Revendas podem receber no Mercado Pago delas**, com preços próprios: o cliente paga direto para a revenda e o sistema desconta os créditos do plano do saldo dela.
- Revendas **compram créditos** em pacotes (ex.: `10=90`, `100=700`) definidos pelo admin.
- Configure em **Painel › Marca e config.** (Access Token de produção em mercadopago.com.br/developers). O botão “Testar conexão” valida o token.

### Marca (white-label)
- Admin muda nome, slogan, cores, logo e link de suporte — o app inteiro, a tela de login e o **app instalado no celular** mudam junto.
- **Masters e revendas podem ter a marca própria**: os clientes delas (e as sub-revendas) veem o app com o nome/logo/cores delas.
- **Link de convite** `seusite.com/?r=usuario_da_revenda`: abre o login com a marca da revenda e permite **cadastro com teste grátis** que já cai na conta dela.

### 📴 Ouvir offline
- Botão **Offline** em qualquer artista, gênero, favoritas ou pelo menu ⋯ de uma música: guarda no aparelho (Cache Storage).
- Sem internet o app abre sozinho no modo offline e toca as músicas salvas, **inclusive avançar/voltar** (o service worker responde pedidos de trecho/Range).
- Página **Offline** mostra espaço usado/livre, “Salvar favoritas”, “Salvar tudo” e “Limpar”. Requer HTTPS (SSL grátis da Hostinger).

### Acervo compartilhado (baixou uma vez, todo mundo ouve)
Toda música baixada por qualquer usuário fica no **acervo do servidor** e qualquer cliente ativo **ouve na hora,
sem baixar de novo** — na busca ela aparece com o botão **Ouvir**, e a página **Explorar acervo** mostra tudo
(“Chegaram agora”, “Em alta no servidor”, artistas e gêneros). Baixar no aparelho só é necessário para o **modo offline**.
Cada cliente continua com a própria biblioteca, favoritas, playlists e contagem de reproduções.

### Playlists
Crie playlists pelo **+** do menu lateral, pelo menu ⋯ de qualquer música ou pelo botão **Playlist** de um
artista/gênero (adiciona tudo de uma vez). Arraste as músicas para mudar a ordem; toque, embaralhe ou salve offline.

### 🎁 Indique e ganhe desconto
Cada cliente tem um link pessoal (`seusite.com/?i=usuario`, com botão de WhatsApp em “Minha conta”).
Quem se cadastra por ele ganha teste grátis e **desconto na 1ª assinatura** (padrão 10%); quando o indicado
assina, **quem indicou ganha desconto acumulável** na próxima renovação (padrão 20% por indicado, até 50%; com 100%
a renovação sai de graça, sem passar pelo Mercado Pago). O indicado fica com a mesma revenda de quem indicou.
Tudo configurável em **Painel › Marca e config.**

## 🔎 Repositórios / APIs pesquisados e usados

| Projeto | Para quê |
|---|---|
| [yt-dlp/yt-dlp](https://github.com/yt-dlp/yt-dlp) | Download do YouTube + extração/conversão de áudio |
| [sigma67/ytmusicapi](https://github.com/sigma67/ytmusicapi) | Referência para a busca direta no YouTube Music (catálogo do artista) |
| [iv-org/invidious](https://github.com/iv-org/invidious), [TeamPiped/Piped](https://github.com/TeamPiped/Piped), [imputnet/cobalt](https://github.com/imputnet/cobalt) | Download alternativo quando o YouTube bloqueia o IP do servidor |
| [eugeneware/ffmpeg-static](https://github.com/eugeneware/ffmpeg-static) | ffmpeg estático em .gz (instala sem xz/root) |
| [denoland/deno](https://github.com/denoland/deno) | Runtime JavaScript que o yt-dlp precisa para o YouTube atual |
| [tranxuanthang/lrclib](https://github.com/tranxuanthang/lrclib) | API gratuita de letras sincronizadas (LRC) |
| [iTunes Search API](https://performance-partners.apple.com/search-api) | Catálogo, gênero, álbum, ano e capas 600×600 (sem chave) |
| [Jamendo API](https://developer.jamendo.com/v3.0) | Músicas livres (Creative Commons) com download liberado |
| [soundscapecloud/soundscape](https://github.com/soundscapecloud/soundscape), [blackcandy-org/blackcandy](https://github.com/blackcandy-org/blackcandy), [sentriz/gonic](https://github.com/sentriz/gonic) | Referências de arquitetura/UX de servidores de música self-hosted |

Tudo foi reimplementado em **PHP puro + SQLite + JavaScript sem build**, para rodar em hospedagem compartilhada sem Composer/Node.

## ⚡ v2.8: downloads sem falhas, player mais leve e seguir artistas
- **Nova tentativa automática**: falha passageira (rede, YouTube, conversão) volta para a fila até 3 vezes antes de virar erro —
  no servidor e no agente do PC. Corrigido: o servidor não "rouba" mais um pedido que o agente está baixando (era a causa de
  "deu erro, mas na segunda vez baixou").
- **Agente do PC mais rápido**: yt-dlp com repetições, envio em pedaços do maior tamanho que a hospedagem aceita (com repetição
  de cada pedaço), progresso ao vivo e m4a publicado sem reconverter. **Atualiza sozinho** a partir desta versão
  (baixe o agente de novo uma última vez no Painel).
- **Player sem travadas**: consulta de downloads a cada 20 s quando só há pedidos esperando o agente (antes: a cada 2 s, sempre),
  visualizador a 30 qps sem sombras no celular, nada de desfoque por trás do player no celular, fila e tela só redesenhadas quando mudam.
- **Seguir artistas**: botão Seguir (com número de seguidores) no artista; página "Artistas que sigo"; no Início,
  "Dos artistas que você segue" com as músicas deles.

## 🎧 v2.7: baixar tudo que tocam + YouTube em 2º plano
- **Painel › Download do YouTube › "Baixar automaticamente tudo que os usuários tocarem"**: música do YouTube ouvida por 30 s
  (e a próxima da fila) vai sozinha para o acervo, sem gastar o limite do plano; o player troca para o arquivo do servidor sem parar.
- Se o YouTube bloquear a hospedagem, o pedido **não vira erro**: fica na fila do agente de download (PC) e baixa quando ele ligar.
- **App 1.1**: YouTube continua tocando com o app minimizado/tela apagada (Painel › App Android › "YouTube em segundo plano no app").
- Player: tela "tocando agora" refeita no celular (letra num cartão, sem sobrepor), selo de origem (YouTube/servidor),
  gestos (arrastar para baixo fecha, deslizar a capa troca de música, mini player para cima abre), pré-carrega a próxima música.

## 📱 App Android (v2.6)
- **APK pronto** em `download/zmusic.apk` (pacote `sbs.zcloudpro.zmusic`): o app abre o site — **mudou o site, mudou o app**, sem APK novo.
- **Segundo plano**: músicas do servidor tocam com a tela apagada, com notificação e controles na tela de bloqueio.
  As do YouTube pausam ao sair do app (termos do YouTube), mas o **Salvar ao ouvir** manda a música para o acervo e o player
  troca sozinho para o arquivo do servidor no mesmo ponto — dali em diante ela também toca em segundo plano.
- `app.json` (aviso de versão nova), `/.well-known/assetlinks.json` (links abrem no app), `privacy.php`, **excluir minha conta**,
  e **modo loja** no painel (esconde downloads do YouTube dentro do app, para a Play Store).
- Código e instruções em [`android/`](android/README.md). O GitHub Actions compila o APK a cada mudança.

## 🚀 Instalação na Hostinger (5 minutos)

1. **Envie os arquivos** para `public_html` (ou uma subpasta / subdomínio) pelo Gerenciador de Arquivos ou Git do hPanel.
2. Em **hPanel › Avançado › Configuração do PHP**:
   - Versão **PHP 8.1+** (8.2/8.3 recomendado).
   - Extensões: `pdo_sqlite`, `curl` (já vêm ativas por padrão).
   - Na aba **Opções do PHP**, garanta que `proc_open` **não** está em `disable_functions`.
   - Recomendo `max_execution_time = 300` e `memory_limit = 256M`.
3. Acesse `https://seudominio.com/install.php`:
   - Crie o **usuário e a senha do administrador**.
   - Clique em **Instalar yt-dlp** (obrigatório), **Deno** (recomendado) e **ffmpeg** (opcional, para MP3/Opus).
4. Abra `https://seudominio.com/`, entre como admin e vá em **Painel › Marca e config.** para colocar sua marca e o Access Token do Mercado Pago. Crie planos, revendas e clientes em **Contas**. 🎉

> Atualizando da v1? Basta subir os arquivos: o banco é migrado sozinho e a senha antiga vira o usuário **admin**.

> **Plano compartilhado x VPS:** os planos Premium/Business costumam permitir `proc_open`. Se o seu
> bloquear, use um **VPS da Hostinger** (funciona 100%) — ou use só a fonte Jamendo, que baixa via cURL.

### (Opcional) Cron para downloads com a página fechada
O app processa a fila sozinho enquanto alguma aba está aberta. Para continuar baixando com tudo fechado,
em **hPanel › Avançado › Cron Jobs**, a cada 5 minutos:
```
/usr/bin/php /home/SEU_USUARIO/domains/SEU_DOMINIO/public_html/worker.php
```

### (Opcional) `config.php`
Copie `config.example.php` para `config.php` para mudar: nome do app, formato (`auto`, `mp3`, `opus`, `m4a`),
bitrate (128 kbps padrão), altura máxima de vídeo, chave da YouTube Data API, `client_id` da Jamendo, país do catálogo.

| Formato | Precisa ffmpeg | ~ Tamanho de 4 min | Observação |
|---|---|---|---|
| `m4a` (AAC) | não | ~4 MB | Padrão sem ffmpeg, sem perda de conversão |
| `mp3` 128k | sim | ~4 MB | Compatível com tudo |
| `opus` 96k | sim | ~3 MB | O mais leve com ótima qualidade |

### 💻 Agente de download (a forma garantida de baixar para a hospedagem)
O YouTube bloqueia downloads vindos de IPs de datacenter, como o da Hostinger. O **agente** resolve isso de graça e sem limite:
ele roda no **seu computador** (internet de casa, que não é bloqueada), pega a fila de downloads do app, baixa com o
[yt-dlp](https://github.com/yt-dlp/yt-dlp) e **envia o arquivo para a hospedagem** — que converte para MP3, organiza em
Gênero/Artista, salva a capa e libera para todos os clientes, sem anúncios.

1. **Painel › Marca e config. › Agente de download › Baixar agente (Windows)**.
2. Dê dois cliques em **“Agente de download.bat”** (na 1ª vez ele baixa o yt-dlp e o Deno sozinho; depois se atualiza).
3. Deixe a janela aberta. O painel mostra 🟢 quando ele está conectado.

Detalhes: o envio é feito em pedaços de 1 MB (funciona com qualquer limite de upload do PHP); cada agente usa um
token secreto (dá para gerar outro no painel); com o agente ligado o servidor nem tenta baixar do YouTube; se ele
desligar no meio de um download, o pedido volta para a fila em 15 minutos. Enquanto a música não baixa, o cliente
ouve na hora pelo botão **▶ Tocar**.

### YouTube pedindo “confirme que você não é um robô”? (comum em hospedagem)
O YouTube bloqueia downloads vindos de IPs de datacenter, como o da Hostinger. O Sonora resolve sozinho:
1. Tenta o **yt-dlp** direto (com seus cookies/proxy, se configurados).
2. Se o YouTube bloquear, baixa por **servidores alternativos open source**:
   [Invidious](https://github.com/iv-org/invidious), [Piped](https://github.com/TeamPiped/Piped) e,
   se você cadastrar um, [Cobalt](https://github.com/imputnet/cobalt). As listas de instâncias públicas
   são buscadas ao vivo e você pode adicionar as suas em **Painel › Marca e config. › Download do YouTube**.
   O arquivo vem em M4A e é convertido para MP3 pelo ffmpeg.
3. Depois de um bloqueio, os próximos downloads vão direto ao alternativo por 1 hora (não perde tempo).

Use **install.php › Testar YouTube** para ver o que está funcionando no seu servidor (com o erro real).
Se o download direto falhar, o teste faz um **auto-ajuste**: tenta o yt-dlp se apresentando como outros
“aplicativos” do YouTube (TV, celular, player embutido…) e salva o primeiro que não for bloqueado.

**Reserva por uso (Apify):** cole um token da [Apify](https://apify.com) (há crédito grátis mensal) em
**Painel › Marca e config. › Download do YouTube**; o ator padrão é `myagizm/youtube-mp3-downloader`.

**Solução mais garantida contra o bloqueio:** a API [youtube-mp36](https://rapidapi.com/ytjar/api/youtube-mp36)
(RapidAPI, tem plano grátis) converte para MP3 no servidor dela — o seu servidor só recebe o arquivo pronto.
Crie a conta, assine o plano Basic, copie a **X-RapidAPI-Key** e cole em
**Painel › Marca e config. › Download do YouTube**. Ela passa a ser a primeira opção dos downloads de áudio.
Para o download direto também funcionar, envie um **cookies.txt** ali mesmo (extensão “Get cookies.txt LOCALLY”
no Chrome, logado no youtube.com — de preferência numa conta secundária). Também há campo para **proxy residencial**.

### Sem Python? Sem problema
O instalador baixa o `yt-dlp_linux`, que já vem com Python embutido. A **busca** nem usa o yt-dlp: o PHP fala
direto com a API interna do YouTube/YouTube Music (inspirado no [ytmusicapi](https://github.com/sigma67/ytmusicapi)),
sem abrir processos no servidor.

### ffmpeg na Hostinger
A Hostinger não tem o descompactador `xz`; por isso o instalador usa os binários estáticos `.gz` do
[ffmpeg-static](https://github.com/eugeneware/ffmpeg-static), descompactados pelo próprio PHP.

## 🗂 Estrutura

```
index.php          interface (SPA)
api.php            API JSON (busca, fila, biblioteca, letras, contas, pagamentos, webhook MP)
brand.php          logos da marca · manifest.php  manifest PWA com a marca
assets/admin.js    painel (visão geral, contas, planos, pagamentos, marca, atividades)
stream.php         streaming com Range (áudio, vídeo, capas)
install.php        verificação do servidor + instalação 1-clique das ferramentas
worker.php         processador da fila + conferência de pagamentos pendentes via cron (opcional)
src/               classes PHP (Db, YouTube, Metadata, Jobs, Worker, Library…)
assets/            app.js, app.css, ícone
storage/library/   suas músicas (Gênero/Artista/Música.ext)
storage/data/      banco SQLite, senha, cookies
bin/               yt-dlp, deno, ffmpeg (instalados pelo install.php)
```

## ⚖️ Uso responsável
Baixe apenas conteúdo que você tem direito de baixar (suas próprias músicas, conteúdo em domínio público
ou Creative Commons — como o catálogo Jamendo — ou com autorização do titular). Os Termos do YouTube e as
leis de direitos autorais se aplicam a você. Mantenha a biblioteca **privada** (o login já vem obrigatório).
