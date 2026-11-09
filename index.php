<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#1f633b">
  <title>FruitScan – AI Fruit Quality Detector</title>
  <meta name="description" content="FruitScan is an AI-assisted web application for detecting whether a fruit appears fresh or rotten using image analysis.">
  <meta property="og:title" content="FruitScan – AI Fruit Quality Detector">
  <meta property="og:description" content="Scan a fruit. Understand its visible condition. Explore AI-assisted fruit quality detection.">
  <meta property="og:type" content="website">
  <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
  <link rel="stylesheet" href="styles/app.css">
  <script type="module" src="app/main.js"></script>
</head>
<body>
  <a class="skip-link" href="#main">Skip to content</a>
  <div id="app"><div class="initial-loading" role="status">Loading FruitScan…</div></div>
  <div id="toasts" class="toasts" aria-live="polite"></div>
  <dialog id="confirm-dialog" aria-labelledby="dialog-title"></dialog>
</body>
</html>
