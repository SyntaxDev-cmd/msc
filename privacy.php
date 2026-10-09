<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$h = fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
$name = Settings::get('brand_name');
$support = Settings::get('support_url');
$base = Settings::baseUrl();
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#0b0b12">
<title>Política de privacidade · <?= $h($name) ?></title>
<style>
  :root { color-scheme: dark; }
  body { margin: 0; background: #0b0b12; color: #e8e8f0; font: 16px/1.65 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  main { max-width: 760px; margin: 0 auto; padding: 32px 18px 64px; }
  h1 { font-size: 28px; margin: 0 0 4px; }
  h2 { font-size: 19px; margin: 28px 0 6px; color: #fff; }
  p, li { color: #c4c4d4; }
  a { color: #a78bfa; }
  .muted { color: #8a8aa0; font-size: 14px; }
  .back { display: inline-block; margin-bottom: 18px; text-decoration: none; }
</style>
</head>
<body>
<main>
  <a class="back" href="./">← Voltar</a>
  <h1>Política de privacidade</h1>
  <p class="muted"><?= $h($name) ?> · <?= $h($base) ?> · atualizada em <?= date('d/m/Y', filemtime(__FILE__)) ?></p>

  <p>Esta política explica quais dados o <b><?= $h($name) ?></b> (site e app Android) coleta, para que usa e como você pode excluí-los.
  Seguimos a Lei Geral de Proteção de Dados (LGPD — Lei 13.709/2018).</p>

  <h2>1. Dados que coletamos</h2>
  <ul>
    <li><b>Conta:</b> nome, usuário, senha (guardada criptografada), e-mail e WhatsApp (opcionais).</li>
    <li><b>Uso do serviço:</b> músicas da sua biblioteca, playlists, favoritas, histórico de reprodução e downloads pedidos.</li>
    <li><b>Pagamentos:</b> valor, plano e situação do pagamento. Os dados do cartão/Pix são tratados diretamente pelo Mercado Pago — nós não os recebemos.</li>
    <li><b>Técnicos:</b> endereço IP (para segurança e limite de tentativas de login) e um cookie de sessão para manter você conectado.</li>
  </ul>

  <h2>2. Para que usamos</h2>
  <ul>
    <li>Fazer o serviço funcionar: login, sua biblioteca, playlists, sugestões de músicas e reprodução.</li>
    <li>Gerenciar o plano, vencimento e pagamentos.</li>
    <li>Segurança e prevenção de abuso.</li>
  </ul>
  <p>Não vendemos seus dados e não mostramos anúncios.</p>

  <h2>3. Compartilhamento</h2>
  <ul>
    <li><b>Mercado Pago</b> — processamento de pagamentos.</li>
    <li><b>YouTube</b> — algumas músicas tocam pelo player oficial do YouTube (incorporado), que segue a
      <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">política de privacidade do Google</a>.</li>
    <li><b>Sua revenda</b> — se a sua conta foi criada por um revendedor, ele pode ver seus dados de cadastro e o plano.</li>
  </ul>

  <h2>4. App Android</h2>
  <p>O app abre este mesmo site. Ele pede permissão de <b>notificações</b> apenas para mostrar os controles da música que está tocando
  (tocar/pausar/próxima) e pode manter a música tocando com a tela apagada. Não acessa contatos, localização, câmera ou microfone.
  Arquivos só são enviados quando você mesmo escolhe um (ex.: logotipo da revenda).</p>

  <h2>5. Guardar e excluir dados</h2>
  <p>Guardamos seus dados enquanto a conta existir. Você pode <b>excluir sua conta a qualquer momento</b> em
  <a href="./#/account">Minha conta › Excluir minha conta</a>: apagamos cadastro, biblioteca, playlists, favoritas e histórico.
  Registros de pagamento são mantidos pelo prazo exigido pela legislação fiscal.</p>
  <p>Se não conseguir entrar na conta, peça a exclusão pelo suporte<?= $support !== '' ? ': <a href="' . $h($support) . '" target="_blank" rel="noopener">' . $h($support) . '</a>' : '' ?>.</p>

  <h2>6. Seus direitos</h2>
  <p>Você pode pedir acesso, correção ou exclusão dos seus dados, e tirar dúvidas sobre esta política, pelo suporte.</p>

  <h2>7. Crianças</h2>
  <p>O serviço não é direcionado a menores de 13 anos.</p>
</main>
</body>
</html>
