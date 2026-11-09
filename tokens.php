<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$user = exigir_login();
if (($user['rol'] ?? '') !== 'admin') {
    header('Location: index.php');
    exit;
}
?><!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Tokens de API · <?= e(cfg('titulo')) ?></title>
    <style>
        :root { color-scheme: light dark; --b: #8884; --mut: #888; }
        body { font: 15px system-ui, sans-serif; margin: 0 auto; padding: 16px; max-width: 1000px; }
        header { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
        header h1 { font-size: 20px; margin: 0; flex: 1; }
        a { color: inherit; }
        input, button { padding: 8px 10px; border: 1px solid var(--b); border-radius: 6px; background: transparent; color: inherit; font: inherit; }
        button { cursor: pointer; }
        form { display: flex; gap: 8px; flex-wrap: wrap; margin: 12px 0; }
        table { border-collapse: collapse; width: 100%; font-size: 14px; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--b); }
        .mut { color: var(--mut); }
        .err { border: 1px solid #c33; color: #c33; padding: 8px 12px; border-radius: 6px; margin: 8px 0; }
        .nuevo { border: 1px solid #2a8; padding: 10px 12px; border-radius: 6px; margin: 8px 0; }
        .nuevo code { display: block; word-break: break-all; margin: 8px 0; user-select: all; }
    </style>
</head>

<body>
    <header>
        <h1>🔑 Tokens de API</h1>
        <a href="index.php">← Volver al panel</a>
    </header>
    <p class="mut">Un token por sistema (por ejemplo, n8n). Se muestra una sola vez al crearlo; aquí solo se guarda su huella.
        Si se filtra, revócalo y crea otro.</p>

    <div id="aviso"></div>
    <form id="f">
        <input id="nombre" placeholder="Nombre (p. ej. n8n producción)" maxlength="100" required>
        <input id="dias" type="number" min="1" max="3650" placeholder="Caduca en (días, opcional)">
        <button type="submit">Crear token</button>
    </form>

    <table>
        <thead><tr><th>Nombre</th><th>Prefijo</th><th>Creado</th><th>Último uso</th><th>Caduca</th><th>Estado</th><th></th></tr></thead>
        <tbody id="lista"></tbody>
    </table>

    <script>
        const CSRF = <?= json_encode(csrf_token()) ?>;
        const $ = id => document.getElementById(id);
        const el = (t, txt, cls) => { const n = document.createElement(t); if (txt !== undefined) n.textContent = txt; if (cls) n.className = cls; return n; };

        async function api(action, body) {
            const opts = body === undefined ? {} : {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify(body)
            };
            const r = await fetch('api.php?action=' + action, opts);
            const j = await r.json();
            if (!r.ok) throw new Error(j.error || 'Error');
            return j;
        }

        async function cargar() {
            const L = $('lista');
            try {
                const { tokens } = await api('tokens');
                L.replaceChildren();
                if (!tokens.length) {
                    const tr = el('tr'); const td = el('td', 'Todavía no hay tokens.', 'mut'); td.colSpan = 7; tr.append(td); L.append(tr);
                }
                tokens.forEach(t => {
                    const tr = el('tr');
                    const caducado = t.caduca && t.caduca < new Date().toISOString().slice(0, 10);
                    const estado = t.activo !== 1 ? 'Revocado' : caducado ? 'Caducado' : 'Activo';
                    tr.append(el('td', t.nombre), el('td', t.prefijo + '…', 'mut'), el('td', t.creado),
                        el('td', t.ultimo_uso ? t.ultimo_uso + (t.ultima_ip ? ' (' + t.ultima_ip + ')' : '') : 'Nunca', 'mut'),
                        el('td', t.caduca || '—', 'mut'), el('td', estado));
                    const td = el('td');
                    if (t.activo === 1) {
                        const b = el('button', 'Revocar');
                        b.onclick = async () => {
                            if (!confirm('¿Revocar «' + t.nombre + '»? n8n dejará de poder enviar datos con él.')) return;
                            try { await api('token_revocar', { id: t.id }); cargar(); } catch (e) { alert(e.message); }
                        };
                        td.append(b);
                    }
                    tr.append(td); L.append(tr);
                });
            } catch (e) {
                $('aviso').replaceChildren(el('div', e.message, 'err'));
            }
        }

        $('f').addEventListener('submit', async ev => {
            ev.preventDefault();
            try {
                const j = await api('token_crear', { nombre: $('nombre').value, dias: parseInt($('dias').value || '0', 10) });
                const box = el('div', undefined, 'nuevo');
                const code = el('code', j.token);
                const cp = el('button', 'Copiar');
                cp.onclick = () => navigator.clipboard.writeText(j.token).then(() => cp.textContent = 'Copiado ✓');
                box.append(el('strong', 'Copia el token ahora: no se volverá a mostrar.'), code, cp);
                $('aviso').replaceChildren(box);
                $('f').reset();
                cargar();
            } catch (e) {
                $('aviso').replaceChildren(el('div', e.message, 'err'));
            }
        });
        cargar();
    </script>
</body>

</html>
