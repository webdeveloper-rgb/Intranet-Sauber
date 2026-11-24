$ErrorActionPreference = 'Stop'

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
  throw 'PHP no está disponible en PATH. Instala PHP con las extensiones pdo_sqlite y mbstring.'
}
if (-not (Get-Command npm.cmd -ErrorAction SilentlyContinue)) {
  throw 'npm no está disponible en PATH.'
}

php scripts/test.php
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

Push-Location frontend
try {
  npm.cmd run build
  if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
} finally {
  Pop-Location
}

git diff --check
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }
