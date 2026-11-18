/**
 * Convierte la URL del navegador en estado de vista y construye enlaces respetando base href.
 */
function basePath() {
  const href = document.querySelector('base')?.href || `${window.location.origin}/`;
  return new URL(href).pathname.replace(/\/$/, '');
}

export function rutaHref(path) {
  const base = basePath();
  return `${base}${path.startsWith('/') ? path : `/${path}`}` || '/';
}

export function leerRuta() {
  const base = basePath();
  let path = window.location.pathname;
  if (base && path.startsWith(`${base}/`)) path = path.slice(base.length);
  else if (path === base) path = '/';

  const partes = path.split('/').filter(Boolean).map((parte) => {
    try { return decodeURIComponent(parte); } catch { return parte; }
  });

  if (partes[0] === 'copias') {
    const copiaId = /^\d+$/.test(partes[1] || '') ? Number(partes[1]) : null;
    return { vista: 'copias', clienteId: null, equipoId: null, copiaId, path: copiaId ? `/copias/${copiaId}` : '/copias' };
  }

  if (partes[0] === 'equipos') {
    const equipoId = /^\d+$/.test(partes[1] || '') ? Number(partes[1]) : null;
    return { vista: 'inicio', clienteId: null, equipoId: equipoId || null, copiaId: null,
      path: equipoId ? `/equipos/${equipoId}` : '/' };
  }

  // Canonicaliza enlaces antiguos sin romper marcadores guardados.
  if (partes[0] === 'inicio') {
    const equipoId = partes[1] === 'equipos' && /^\d+$/.test(partes[2] || '') ? Number(partes[2]) : null;
    return { vista: 'inicio', clienteId: null, equipoId, copiaId: null,
      path: equipoId ? `/equipos/${equipoId}` : '/' };
  }

  if (partes[0] === 'informes') {
    return { vista: 'informes', clienteId: null, equipoId: null, copiaId: null, path: '/informes' };
  }

  if (partes[0] === 'clientes') {
    const cliente = partes[1] || null;
    const equipo = partes[2] === 'equipos' && /^\d+$/.test(partes[3] || '') ? Number(partes[3]) : null;
    if (!cliente) return { vista: 'clientes', clienteId: null, equipoId: null, path: '/clientes' };
    if (['sin-asignar', 'sin-satbk', 'cliente-inactivo'].includes(cliente) || /^\d+$/.test(cliente)) {
      const clienteId = /^\d+$/.test(cliente) ? Number(cliente) : cliente;
      const pathCliente = `/clientes/${encodeURIComponent(cliente)}`;
      return {
        vista: 'clientes', clienteId, equipoId: equipo,
        path: equipo ? `${pathCliente}/equipos/${equipo}` : pathCliente,
      };
    }
    return { vista: 'clientes', clienteId: null, equipoId: null, path: '/clientes' };
  }

  if (partes[0] === 'eventos') {
    const parametroEquipo = new URLSearchParams(window.location.search).get('equipo_id');
    const candidatoEquipo = parametroEquipo && /^\d+$/.test(parametroEquipo) ? Number(parametroEquipo) : null;
    const equipoId = Number.isSafeInteger(candidatoEquipo) && candidatoEquipo > 0 ? candidatoEquipo : null;
    return {
      vista: 'eventos', clienteId: null, equipoId, copiaId: null,
      path: equipoId ? `/eventos?equipo_id=${equipoId}` : '/eventos',
    };
  }

  if (!partes.length) return { vista: 'inicio', clienteId: null, equipoId: null, copiaId: null, path: '/' };
  return { vista: 'inicio', clienteId: null, equipoId: null, copiaId: null, path: '/' };
}
