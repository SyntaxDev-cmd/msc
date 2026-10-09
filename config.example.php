<?php
/**
 * Copie este arquivo para config.php e ajuste o que quiser.
 * Tudo aqui é opcional — o sistema funciona com os valores padrão.
 */
return [
    // Nome exibido no app
    'app_name' => 'Sonora',

    // País da loja iTunes usada para catálogo, gênero e capas (BR = gêneros em PT)
    'country' => 'BR',

    // Formato do áudio salvo:
    //  'auto' -> mp3 se houver ffmpeg, senão m4a (AAC original, sem conversão)
    //  'mp3'  -> MP3 (exige ffmpeg)
    //  'opus' -> Opus (o mais leve, ótima qualidade em 96 kbps; exige ffmpeg)
    //  'm4a'  -> AAC original do YouTube (leve, não precisa de ffmpeg)
    'audio_format' => 'auto',

    // Qualidade MP3/Opus quando há conversão (kbps). 128 = leve e bom; 192 = alta
    'audio_bitrate' => 128,

    // Altura máxima dos vídeos baixados (quando você escolhe "vídeo")
    'video_max_height' => 720,

    // Quantidade de resultados por busca
    'max_results' => 25,

    // Opcional: chave da YouTube Data API v3 (busca mais rápida). Vazio = usa yt-dlp
    'youtube_api_key' => '',

    // Opcional: client_id da API Jamendo (músicas livres/Creative Commons) — https://devportal.jamendo.com
    'jamendo_client_id' => '',

    // Tempo máximo (s) que o processador de downloads roda por requisição web
    'worker_max_seconds' => 270,

    // Fuso horário
    'timezone' => 'America/Sao_Paulo',
];
