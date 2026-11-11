<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
require __DIR__ . '/src/api_utilities.php';
$user = exigir_login();

$err = '';
try {
    $cat = api_sync_catalogo_inicial();
} catch (Throwable $ex) {
    error_log('[copias] ' . $ex->getMessage());
    $err = cfg('debug') ? $ex->getMessage() : 'No se pudo leer el catálogo de clientes.';
    $cat = ['clientes' => [], 'ultima' => null, 'configurado' => false];
}
$esAdmin = ($user['rol'] ?? '') === 'admin';
?><!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Clientes ·
        <?= e(cfg('titulo')) ?>
    </title>
    <style>
        :root {
            color-scheme: light dark;
            --b: #8884;
            --mut: #888;
        }

        body {
            font: 15px system-ui, sans-serif;
            margin: 0;
            padding: 16px;
            max-width: 1000px;
            margin-inline: auto;
        }

        header {
            display: flex;
            gap: 12px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 12px;
        }

        header h1 {
            font-size: 20px;
            margin: 0;
            flex: 1;
        }

        a {
            color: inherit;
        }

        input[type=search] {
            padding: 8px 10px;
            border: 1px solid var(--b);
            border-radius: 6px;
            width: 100%;
            box-sizing: border-box;
            background: transparent;
            color: inherit;
        }

        button {
            padding: 8px 12px;
            border: 1px solid var(--b);
            border-radius: 6px;
            background: transparent;
            color: inherit;
            cursor: pointer;
        }

        .estado {
            color: var(--mut);
            font-size: 13px;
            margin: 8px 0;
        }

        .err {
            border: 1px solid #c33;
            color: #c33;
            padding: 8px 12px;
            border-radius: 6px;
            margin: 8px 0;
        }

        details {
            border: 1px solid var(--b);
            border-radius: 8px;
            margin: 6px 0;
            padding: 0 12px;
        }

        summary {
            cursor: pointer;
            padding: 10px 0;
            display: flex;
            gap: 8px;
            align-items: baseline;
        }

        summary .n {
            font-weight: 600;
            flex: 1;
        }

        summary .c {
            color: var(--mut);
            font-size: 13px;
        }

        .datos {
            color: var(--mut);
            font-size: 13px;
            margin: 0 0 8px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-bottom: 10px;
            font-size: 14px;
        }

        th,
        td {
            text-align: left;
            padding: 5px 8px;
            border-bottom: 1px solid var(--b);
        }

        .vacio {
            color: var(--mut);
            padding: 8px 0 12px;
        }

        h2 {
            font-size: 16px;
            margin-top: 24px;
        }
    </style>
</head>

<body>
    <header>
        <h1>🏢 Clientes</h1>
        <a href="index.php">← Volver al panel</a>
        <?php if ($esAdmin): ?><button id="sync" type="button">🔄 Sincronizar ahora</button>
        <?php endif; ?>
    </header>

    <?php if ($err): ?>
        <div class="err">
            <?= e($err) ?>
        </div>
    <?php endif; ?>
    <?php if (!$cat['configurado']): ?>
        <div class="err">La API no está configurada en config/config.php (sección <code>api_sync</code> o
            <code>albelink</code>).</div>
    <?php endif; ?>
    <?php if (!empty($cat['ultima']) && $cat['ultima']['ok'] === 0): ?>
        <div class="err">Última sincronización fallida (
            <?= e($cat['ultima']['fecha']) ?>):
            <?= e($cat['ultima']['mensaje']) ?>
        </div>
    <?php endif; ?>

    <input type="search" id="q" placeholder="Buscar cliente, código, CIF o correo…" autofocus>
    <div class="estado" id="estado"></div>
    <div id="lista"></div>

    <script type="application/json"
        id="datos"><?= json_encode($cat, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
    <script>
        const cat = JSON.parse(document.getElementById('datos').textContent);
        const $ = id => document.getElementById(id);

        function el(tag, cls, txt) {
            const n = document.createElement(tag);
            if (cls) n.className = cls;
            if (txt !== undefined) n.textContent = txt;
            return n;
        }
        function pintar(filtro) {
            const f = (filtro || '').trim().toLowerCase();
            const L = $('lista');
            L.replaceChildren();
            let n = 0;
            cat.clientes.forEach(c => {
                const hay = [c.codigo, c.nombre, c.cif, c.email, c.localidad].join(' ').toLowerCase();
                if (f && !hay.includes(f)) return;
                n++;
                const d = el('details');
                const s = el('summary');
                s.append(el('span', 'n', c.nombre || '(sin nombre)'),
                    el('span', 'c', c.codigo || ''));
                d.appendChild(s);
                d.appendChild(el('p', 'datos', [c.cif, c.telefono, c.email, [c.localidad, c.provincia].filter(Boolean).join(', ')].filter(Boolean).join(' · ')));
                L.appendChild(d);
            });
            if (!n) L.appendChild(el('div', 'vacio', cat.clientes.length ? 'Sin resultados.' : 'Todavía no hay clientes sincronizados.'));
            const u = cat.ultima;
            $('estado').textContent = n + ' de ' + cat.clientes.length + ' clientes'
                + (u ? ' · última sincronización: ' + u.fecha + (u.ok === 1 ? ' (OK)' : u.ok === 0 ? ' (con error)' : '') : '');
        }
        $('q').addEventListener('input', e => pintar(e.target.value));
        pintar('');

        const bs = $('sync');
        if (bs) bs.addEventListener('click', async () => {
            bs.disabled = true; bs.textContent = 'Sincronizando…';
            try {
                const r = await fetch('api.php?action=sync', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode(csrf_token()) ?>},
                    body: '{}'
                });
                const j = await r.json();
                if (!r.ok) throw new Error(j.error || 'Error');
                location.reload();
            } catch (e) {
                alert('No se pudo sincronizar: ' + e.message);
                bs.disabled = false; bs.textContent = '🔄 Sincronizar ahora';
            }
        });
    </script>
</body>

</html>
