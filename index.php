<?php ?><!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="theme-color" content="#030b03">
  <title>STREET DOGS</title>
  <link rel="stylesheet" href="css/terminal.css?v=<?= filemtime(__DIR__.'/css/terminal.css') ?>">
</head>
<body>
<div id="crt-wrap">
  <div id="terminal">
    <div id="status-bar"></div>
    <div id="log-area">
      <div id="scene">
        <div class="scene-layer" id="scene-bg"></div>
        <div class="scene-layer" id="scene-mob"></div>
        <div class="scene-layer" id="scene-player"></div>
      </div>
      <div id="log-content"></div>
    </div>
    <div id="cmd-area">
      <div id="cmd-title">──</div>
      <div id="cmd-buttons"></div>
    </div>
  </div>
</div>
<script src="js/effects.js?v=<?= filemtime(__DIR__.'/js/effects.js') ?>"></script>
</body>
</html>
