/**
 * Reglas puras de clasificacion, filtros, alertas, contadores y claves de equipo.
 */
// Reglas de negocio del panel (sin interfaz).
// ERR: error sin revisar | REV: EN REVISIÓN (arreglo descrito, en vigilancia) | OK: copia OK o error con vigilancia cerrada
export const MIN_PALABRAS = 15;

export const LBL = { OK: "OK", ERR: "ERROR", REV: "EN REVISIÓN" };

export const ef = (c) =>
    c.estado === "OK" ?
    "OK" :
    c.revisado ?
    c.seg === "cerrado" ?
    "OK" :
    "REV" :
    "ERR";

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
export const vencido = (c) =>
    ef(c) === "REV" &&
    c.seg === "vigilancia" &&
    !!c.recordar &&
    c.recordar <= ahora().slice(0, 10);

export const FILTROS = [
    ["todos", "Todos"],
    ["pend", "Errores"],
    ["sin", "Sin copia recibida"],
    ["rep", "Errores repetidos"],
    ["rev", "En revisión"],
    ["ok", "OK"],
    ["notas", "Con notas"],
    ["tras", "OK tras arreglo"],
    ["venc", "Recordatorios vencidos"],
    ["atr", "Errores atrasados"],
];

export const TEST = {
    todos: () => true,
    pend: (c) => ef(c) === "ERR",
    sin: (c) => c.codigo === "SIN_COPIA",
    rep: (c, h, rep) => c.estado === "ERROR" && c.codigo !== "SIN_COPIA" && (rep?.[claveEquipo(c)] || 0) > 1,
    rev: (c) => ef(c) === "REV",
    ok: (c) => ef(c) === "OK",
    notas: (c) => (c.notas || []).length > 0,
    tras: (c) => c.estado === "ERROR" && ef(c) === "OK",
    venc: (c) => vencido(c),
    atr: (c, h) => atrasado(c, h),
};

// Filtros que muestran contador (clave del contador) y los que avisan en rojo
export const EXTRA = { sin: "sc", rep: "rr", notas: "c", tras: "r", venc: "v", atr: "a" };
export const ALERTA = { sin: 1, venc: 1, atr: 1 };

export function contadores(data, horas) {
    const copias = data.filter((c) => c.codigo !== "SIN_COPIA");
    const rep = repeticiones(copias);
    return {
        p: copias.filter(TEST.pend).length,
        sc: data.filter(TEST.sin).length,
        rr: copias.filter((c) => TEST.rep(c, horas, rep)).length,
        w: copias.filter(TEST.rev).length,
        r: copias.filter(TEST.tras).length,
        o: copias.filter(TEST.ok).length,
        c: copias.filter(TEST.notas).length,
        v: copias.filter(vencido).length,
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