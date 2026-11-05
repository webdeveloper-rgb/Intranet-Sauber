// Reglas de negocio del panel (sin interfaz).
// ERR: error sin revisar | REV: EN REVISIÓN (arreglo descrito, en vigilancia) | OK: copia OK o error con vigilancia cerrada
export const MIN_PALABRAS = 15;

export const LBL = { OK: 'OK', ERR: 'ERROR', REV: 'EN REVISIÓN' };

export const ef = (c) => (c.estado === 'OK' ? 'OK' : c.revisado ? (c.seg === 'cerrado' ? 'OK' : 'REV') : 'ERR');

export const norm = (s) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
export const palabras = (t) => t.trim().split(/\s+/).filter(Boolean).length;

export const ahora = () =>
  new Date(Date.now() - new Date().getTimezoneOffset() * 6e4).toISOString().slice(0, 16).replace('T', ' ');

const edadH = (c) => (Date.now() - new Date(c.fecha.replace(' ', 'T')).getTime()) / 36e5;
export const atrasado = (c, h) => ef(c) === 'ERR' && edadH(c) > h;
export const vencido = (c) =>
  ef(c) === 'REV' && c.seg === 'vigilancia' && !!c.recordar && c.recordar <= ahora().slice(0, 10);

export const FILTROS = [
  ['todos', 'Todos'],
  ['pend', 'Errores'],
  ['rep', 'Errores repetidos'],
  ['rev', 'En revisión'],
  ['ok', 'OK'],
  ['notas', 'Con notas'],
  ['tras', 'OK tras arreglo'],
  ['venc', 'Recordatorios vencidos'],
  ['atr', 'Errores atrasados'],
];

export const TEST = {
  todos: () => true,
  pend: (c) => ef(c) === 'ERR',
  rep: (c, h, rep) => c.estado === 'ERROR' && (rep?.[claveEquipo(c)] || 0) > 1,
  rev: (c) => ef(c) === 'REV',
  ok: (c) => ef(c) === 'OK',
  notas: (c) => (c.notas || []).length > 0,
  tras: (c) => c.estado === 'ERROR' && ef(c) === 'OK',
  venc: (c) => vencido(c),
  atr: (c, h) => atrasado(c, h),
};

// Filtros que muestran contador (clave del contador) y los que avisan en rojo
export const EXTRA = { rep: 'rr', notas: 'c', tras: 'r', venc: 'v', atr: 'a' };
export const ALERTA = { venc: 1, atr: 1 };

export function contadores(data, horas) {
  const rep = repeticiones(data);
  return {
    p: data.filter(TEST.pend).length,
    rr: data.filter((c) => TEST.rep(c, horas, rep)).length,
    w: data.filter(TEST.rev).length,
    r: data.filter(TEST.tras).length,
    o: data.filter(TEST.ok).length,
    c: data.filter(TEST.notas).length,
    v: data.filter(vencido).length,
    a: data.filter((c) => atrasado(c, horas)).length,
  };
}

// Cuántos errores acumula cada equipo (cliente|equipo)
export function repeticiones(data) {
  const m = {};
  data.forEach((c) => {
    if (c.estado === 'ERROR') {
      const k = c.cliente + '|' + c.equipo;
      m[k] = (m[k] || 0) + 1;
    }
  });
  return m;
}
export const claveEquipo = (c) => c.cliente + '|' + c.equipo;
