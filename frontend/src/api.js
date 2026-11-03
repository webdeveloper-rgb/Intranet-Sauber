// Única capa de comunicación con el servidor (api.php).
// Si algún día cambias el backend (Node JS u otro), solo tocas este fichero.
let csrf = '';

async function call(action, body) {
  const r = await fetch('api.php?action=' + action, {
    method: body ? 'POST' : 'GET',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
    body: body ? JSON.stringify(body) : undefined,
    credentials: 'same-origin',
  });
  if (r.status === 401) {
    location.href = 'login.php';
    throw new Error('Sesión caducada');
  }
  let j = {};
  try { j = await r.json(); } catch { /* respuesta sin JSON */ }
  if (!r.ok) throw new Error(j.error || 'Error del servidor (' + r.status + ')');
  return j;
}

export async function iniciarSesion() {
  const s = await call('sesion');
  csrf = s.csrf;
  return s; // { usuario, nombre, csrf }
}

export const api = call;
