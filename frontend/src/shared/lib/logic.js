/**
 * Reglas puras de clasificacion, filtros, alertas, contadores y claves de equipo.
 */
// Reglas de negocio del panel (sin interfaz).
// ERR: error sin revisar | REV: EN REVISIÓN (arreglo descrito, en vigilancia) | OK: copia OK o error con vigilancia cerrada
export const MIN_PALABRAS = 15;

export const LBL = { OK: "OK", ERR: "ERROR", WA: "ADVERTENCIA", RUN: "EN CURSO", INC: "POR CLASIFICAR" };

export const ef = (c) => ({ OK: 'OK', ERROR: 'ERR', WARNING: 'WA', RUNNING: 'RUN', REVISION: 'INC' }[c.estado] || 'INC');

export const norm = (s) => s.normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();
export const palabras = (t) => t.trim().split(/\s+/).filter(Boolean).length;

// Tipo de copia: equipo de usuario, servidor o uno de los programas de gestión.
export const TIPOS = {
    usuario: "Usuario",
    servidor: "Servidor",
    programa: "Programa",
};
export const PROGRAMAS = [
    "EuroAgro",
    "EuroPos",
    "EuroExtintor",
    "EuroMetrics",
    "DivaCon",
    "DivaGes",
    "BDP",
];
export const etiquetaTipo = (c) => c.programa || TIPOS[c.tipo] || "—";
// v: '' (todos) | 'usuario' | 'servidor' | 'programa' | 'programa:EuroAgro'
export const coincideTipo = (c, v) =>
    !v || (v.startsWith("programa:") ? c.programa === v.slice(9) : c.tipo === v);

export const ahora = () =>
    new Date(Date.now() - new Date().getTimezoneOffset() * 6e4)
    .toISOString()
    .slice(0, 16)
    .replace("T", " ");

const edadH = (c) =>
    (Date.now() - new Date(c.fecha.replace(" ", "T")).getTime()) / 36e5;
export const atrasado = (c, h) => ef(c) === "ERR" && edadH(c) > h;
export const FILTROS = [
    ["todos", "Todos"],
    ["pend", "Errores"],
    ["sin", "Sin copia recibida"],
    ["rep", "Errores repetidos"],
    ["warning", "Advertencias"],
    ["running", "En curso"],
    ["inc", "Por clasificar"],
    ["ok", "OK"],
    ["notas", "Con notas"],
    ["atr", "Errores atrasados"],
];

export const TEST = {
    todos: () => true,
    pend: (c) => ef(c) === "ERR",
    sin: (c) => c.codigo === "SIN_COPIA",
    rep: (c, h, rep) => c.estado === "ERROR" && c.codigo !== "SIN_COPIA" && (rep?.[claveEquipo(c)] || 0) > 1,
    warning: (c) => ef(c) === "WA",
    running: (c) => ef(c) === "RUN",
    inc: (c) => ef(c) === "INC",
    ok: (c) => ef(c) === "OK",
    notas: (c) => (c.notas || []).length > 0,
    atr: (c, h) => atrasado(c, h),
};

// Filtros que muestran contador (clave del contador) y los que avisan en rojo
export const EXTRA = { sin: "sc", rep: "rr", warning: "wa", running: "run", inc: "inc", notas: "c", atr: "a" };
export const ALERTA = { sin: 1, atr: 1 };

export function contadores(data, horas) {
    const copias = data.filter((c) => c.codigo !== "SIN_COPIA");
    const rep = repeticiones(copias);
    return {
        p: copias.filter(TEST.pend).length,
        sc: data.filter(TEST.sin).length,
        rr: copias.filter((c) => TEST.rep(c, horas, rep)).length,
        wa: copias.filter(TEST.warning).length,
        run: copias.filter(TEST.running).length,
        inc: copias.filter(TEST.inc).length,
        o: copias.filter(TEST.ok).length,
        c: copias.filter(TEST.notas).length,
        a: copias.filter((c) => atrasado(c, horas)).length,
    };
}

// Cuántos errores acumula cada equipo (cliente|equipo)
export function repeticiones(data) {
    const m = {};
    data.forEach((c) => {
        if (c.estado === "ERROR" && c.codigo !== "SIN_COPIA") {
            const k = c.cliente + "|" + c.equipo;
            m[k] = (m[k] || 0) + 1;
        }
    });
    return m;
}
export const claveEquipo = (c) => c.cliente + "|" + c.equipo;
