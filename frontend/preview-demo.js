// Only enabled by `vite --mode demo` on the local development server.
import { readFile } from 'node:fs/promises';

export default function previewDemo() {
  const fecha = (horas = 0) => new Date(Date.now() - horas * 3600000).toLocaleString('sv-SE', { timeZone: 'Europe/Madrid' }).slice(0, 16);
  const clientes = [
    { id: 1, codigo: 'C001', nombre: 'Clínica Alameda', tiene_satbk: true },
    { id: 2, codigo: 'C002', nombre: 'Asesoría Norte', tiene_satbk: true },
    { id: 3, codigo: 'C003', nombre: 'Transportes Levante', tiene_satbk: true },
    { id: 4, codigo: 'C004', nombre: 'Estudio Horizonte', tiene_satbk: false },
  ];
  const equipos = Array.from({ length: 18 }, (_, i) => {
    const cliente = i < 16 ? clientes[Math.floor(i / 4)] : null;
    return {
      id: i + 1, nombre: i % 4 === 0 ? `SRV-${['ALAMEDA', 'NORTE', 'LEVANTE', 'HORIZONTE', 'NUEVO'][Math.floor(i / 4)]}` : `PC-OFICINA-${String(i + 1).padStart(2, '0')}`,
      monitor_alias: '', cliente_id: cliente?.id || null, cliente: cliente?.nombre || null,
      tipo: i % 4 === 0 ? 'servidor' : 'usuario', tipo_manual: true,
      software: i < 12 ? 'Veeam' : '', programa: '', origen: 'monitor', sugerido: '',
      tiene_monitor: true, tiene_copias: i < 12, vigilar: i < 12 ? 1 : 0, dias_copia: '1111100',
      monitor_last_seen: fecha(i === 5 ? 28 : i === 9 ? 32 : 0), fecha_creacion: fecha(240).slice(0, 10),
      errores_pendientes: i === 0 ? 3 : i === 4 ? 2 : i === 2 ? 1 : 0,
      total_errores_evento: i === 0 ? 2 : i === 7 ? 3 : 0,
      eventos_windows: i === 0 ? 1 : i === 7 ? 3 : 0, alertas_hardware: i === 0 ? 1 : 0,
      critico: i === 0, sin_copia: i === 4 || i === 8, sin_informe: i === 5 || i === 9,
      copias_en_curso: [3, 6, 10].includes(i) ? 1 : 0, pendientes: 0,
      errores_evento: i === 0 ? [{ titulo: 'Disco del servidor con fallo SMART previsto', fecha: fecha(1) }]
        : i === 7 ? [{ titulo: 'Cierre inesperado de aplicación', fecha: fecha(2) }] : [],
      error_reviewed_at: null, error_reviewed_by: null, error_review_desc: null,
    };
  });
  const copias = Array.from({ length: 60 }, (_, i) => {
    const equipo = equipos[i % 12];
    const estado = i < 6 ? 'ERROR' : i < 9 ? 'WARNING' : i < 12 ? 'RUNNING' : i < 14 ? 'REVISION' : 'OK';
    return { id: i + 1, equipo_id: equipo.id, equipo: equipo.nombre, cliente: equipo.cliente,
      cliente_id: equipo.cliente_id, fecha: fecha(i / 3), cursor_fecha: fecha(i / 3), estado,
      software: 'Veeam', tipo: equipo.tipo, programa: '', destino: 'Repositorio de copias',
      tamano: '24 GB', duracion: '00:18:42', revisado: 0, notas: [], seg: null,
      codigo: estado === 'ERROR' ? 'BACKUP_FAILED' : '',
      desc_error: estado === 'ERROR' ? 'No se pudo acceder al repositorio de destino. Ejemplo de demostración.' : '',
      resultado_original: estado, clasificacion_fuente: 'demo', clasificacion_evidencia: 'Datos ficticios para la preview',
    };
  });
  const eventos = [{ id: 1, equipo_id: 1, equipo: equipos[0].nombre, cliente: clientes[0].nombre,
    event_time: fecha(1), level: 'Critical', nivel: 'Critical', event_id: 41, log_name: 'System', source: 'Kernel-Power', message: 'Evento de ejemplo para revisar el diseño.' }];
  return {
    name: 'sauber-local-demo',
    transformIndexHtml() {
      return [
        { tag: 'base', attrs: { href: '/' }, injectTo: 'head-prepend' },
        { tag: 'div', attrs: { role: 'status', style: 'position:fixed;bottom:12px;right:16px;z-index:1000;padding:8px 14px;background:#fff1d9;color:#774600;border:1px solid #e0b65c;border-radius:8px;font:12px system-ui;box-shadow:0 2px 10px #0002;pointer-events:none' }, children: 'PREVIEW LOCAL · Datos ficticios', injectTo: 'body' },
      ];
    },
    configureServer(server) {
      server.middlewares.use(async (req, res, next) => {
        const url = new URL(req.url, 'http://localhost');
        const json = (data, status = 200) => { res.statusCode = status; res.setHeader('Content-Type', 'application/json; charset=utf-8'); res.setHeader('Cache-Control', 'no-store'); res.end(JSON.stringify(data)); };
        if (url.pathname === '/assets/logo.png') {
          res.setHeader('Content-Type', 'image/png');
          res.end(await readFile(new URL('../assets/logo.png', import.meta.url))); return;
        }
        if (['/login.php', '/logout.php'].includes(url.pathname)) { res.writeHead(302, { Location: '/' }); res.end(); return; }
        if (url.pathname !== '/api.php') return next();
        const action = url.searchParams.get('action');
        if (action === 'sesion') return json({ usuario: 'demo', nombre: 'Técnico Demo', rol: 'admin', csrf: 'local-demo' });
        if (action === 'novedades') return json({ m: 60, staging: 0, ingesta: 1, ausentes: 'demo', eventos: 1, alertas: 'demo' });
        if (action === 'dashboard_resumen') return json({
          actualizado: new Date().toISOString(), equipos: equipos.filter((e) => e.errores_pendientes || e.total_errores_evento || e.sin_copia || e.sin_informe || e.copias_en_curso),
          resumen: { total_equipos: equipos.length, agentes: equipos.length, sin_asignar: 2, sin_copia: 2, sin_informe: 2 },
          copias: Object.entries(copias.reduce((n, c) => ({ ...n, [c.estado]: (n[c.estado] || 0) + 1 }), {})).map(([estado, total]) => ({ estado, total })),
          integraciones: { ingesta: { ok: 1, fecha: fecha(), retrasada: false }, clientes: { ok: 1, fecha: fecha(1) } },
        });
        if (action === 'catalogo') return json({ clientes, configurado: true, ultima: { ok: 1, fecha: fecha(1) } });
        if (action === 'equipos') return json({ equipos, correo: { ok: 1, fecha: fecha(), alerta: false } });
        if (action === 'list') return json({ copias, truncado: false });
        if (action === 'informe_copias') {
          const estado = url.searchParams.get('estado');
          const rows = copias.filter((c) => (!estado || estado === 'all' || c.estado === estado)
            && (!url.searchParams.get('desde') || c.fecha.slice(0, 10) >= url.searchParams.get('desde'))
            && (!url.searchParams.get('hasta') || c.fecha.slice(0, 10) <= url.searchParams.get('hasta')));
          return json({ copias: rows, total: rows.length, pagina: 1, por_pagina: 100 });
        }
        if (action === 'equipo_historial') {
          let body = ''; for await (const chunk of req) body += chunk;
          const id = JSON.parse(body || '{}').equipo_id;
          return json({ copias: copias.filter((c) => c.equipo_id === Number(id)), hay_mas_historial: false });
        }
        if (action === 'monitor_equipo') return json({ monitor: null, alertas: [], monitor_last_seen: fecha() });
        if (action === 'monitor_backup_activity') return json({ actividades: [] });
        if (action === 'monitor_eventos') return json({ eventos: eventos.filter((e) => !url.searchParams.get('equipo_id') || e.equipo_id === Number(url.searchParams.get('equipo_id'))), total: 1, pagina: 1, por_pagina: 25 });
        if (action === 'notificaciones') return json({ notificaciones: [], total: 0, pagina: 1, por_pagina: 25 });
        if (action === 'tokens') return json({ tokens: [] });
        return json({ error: 'Esta preview utiliza datos ficticios. Las acciones de modificación están desactivadas.' }, 403);
      });
    },
  };
}
