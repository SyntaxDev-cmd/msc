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

**Busca e download**
- 4 fontes: **Músicas** (catálogo iTunes, com gênero/álbum/capa oficiais), **Artista (discografia)** — lista até 150 músicas do artista para baixar tudo com 1 clique —, **YouTube** (vídeos crus) e **Músicas livres** (Jamendo, Creative Commons).
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

**Segurança** — login com senha (hash bcrypt), proteção CSRF, nenhum comando passa por shell (sem injeção), mídia e banco bloqueados para acesso direto.

## 🔎 Repositórios / APIs pesquisados e usados

| Projeto | Para quê |
|---|---|
| [yt-dlp/yt-dlp](https://github.com/yt-dlp/yt-dlp) | Busca e download de YouTube + extração/conversão de áudio |
| [yt-dlp/FFmpeg-Builds](https://github.com/yt-dlp/FFmpeg-Builds) | ffmpeg estático (sem root) para converter para MP3/Opus |
| [denoland/deno](https://github.com/denoland/deno) | Runtime JavaScript que o yt-dlp precisa para o YouTube atual |
| [tranxuanthang/lrclib](https://github.com/tranxuanthang/lrclib) | API gratuita de letras sincronizadas (LRC) |
| [iTunes Search API](https://performance-partners.apple.com/search-api) | Catálogo, gênero, álbum, ano e capas 600×600 (sem chave) |
| [Jamendo API](https://developer.jamendo.com/v3.0) | Músicas livres (Creative Commons) com download liberado |
| [soundscapecloud/soundscape](https://github.com/soundscapecloud/soundscape), [blackcandy-org/blackcandy](https://github.com/blackcandy-org/blackcandy), [sentriz/gonic](https://github.com/sentriz/gonic) | Referências de arquitetura/UX de servidores de música self-hosted |

Tudo foi reimplementado em **PHP puro + SQLite + JavaScript sem build**, para rodar em hospedagem compartilhada sem Composer/Node.

## 🚀 Instalação na Hostinger (5 minutos)

1. **Envie os arquivos** para `public_html` (ou uma subpasta / subdomínio) pelo Gerenciador de Arquivos ou Git do hPanel.
2. Em **hPanel › Avançado › Configuração do PHP**:
   - Versão **PHP 8.1+** (8.2/8.3 recomendado).
   - Extensões: `pdo_sqlite`, `curl` (já vêm ativas por padrão).
   - Na aba **Opções do PHP**, garanta que `proc_open` **não** está em `disable_functions`.
   - Recomendo `max_execution_time = 300` e `memory_limit = 256M`.
3. Acesse `https://seudominio.com/install.php`:
   - Crie sua **senha**.
   - Clique em **Instalar yt-dlp** (obrigatório), **Deno** (recomendado) e **ffmpeg** (opcional, para MP3/Opus).
4. Abra `https://seudominio.com/` e pesquise! 🎉

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

### YouTube pedindo “confirme que você não é um robô”?
IPs de datacenter às vezes recebem esse bloqueio. Exporte os cookies do YouTube do seu navegador (extensão
“Get cookies.txt LOCALLY”), salve como `storage/data/cookies.txt` pelo Gerenciador de Arquivos, e pronto.
Atualizar o yt-dlp no `install.php` também resolve a maioria dos erros.

## 🗂 Estrutura

```
index.php          interface (SPA)
api.php            API JSON (busca, fila, biblioteca, letras)
stream.php         streaming com Range (áudio, vídeo, capas)
install.php        verificação do servidor + instalação 1-clique das ferramentas
worker.php         processador da fila via cron (opcional)
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
