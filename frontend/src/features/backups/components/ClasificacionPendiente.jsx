import { useMemo, useState } from 'react';

const TIPOS = [
  ['OK', 'OK'],
  ['ERROR', 'Error confirmado'],
  ['WARNING', 'Advertencia'],
  ['RUNNING', 'En curso'],
];
const NIVELES_ERROR = [
  ['leve', 'Error leve'],
  ['normal', 'Error'],
  ['grave', 'Error grave'],
  ['critico', 'Error crítico'],
];

const claveGrupo = (c) => [
  c.software || '',
  c.codigo || '',
  c.resultado_original || '',
  c.desc_error || '',
  c.clasificacion_evidencia || '',
  c.maquina_virtual || '',
  c.tarea || '',
].join('\u001f');

const grupoPayload = (g) => ({
  software: g.software || '',
  codigo: g.codigo || '',
  severidad: g.severidad || 'normal',
  resultado_original: g.resultado_original || '',
  desc_error: g.desc_error || '',
  clasificacion_evidencia: g.clasificacion_evidencia || '',
  maquina_virtual: g.maquina_virtual || '',
  tarea: g.tarea || '',
});

function GrupoPorClasificar({ grupo, onClasificar }) {
  const [estado, setEstado] = useState('ERROR');
  const [severidad, setSeveridad] = useState(grupo.severidad || 'normal');
  const [codigo, setCodigo] = useState(grupo.codigo || '');
  const [descripcion, setDescripcion] = useState(grupo.desc_error || grupo.clasificacion_evidencia || '');
  const [ocupado, setOcupado] = useState(false);
  const enviar = async () => {
    setOcupado(true);
    const ok = await onClasificar(grupoPayload(grupo), {
      estado,
      severidad: estado === 'ERROR' ? severidad : 'normal',
      codigo: codigo.trim(),
      desc_error: descripcion.trim(),
    });
    if (!ok) setOcupado(false);
  };
  return <article className="clasificacion-item">
    <div className="clasificacion-error">
      <div className="clasificacion-meta">
        <span>{grupo.total} copia{grupo.total === 1 ? '' : 's'}</span>
        <span>{grupo.equipos.size} equipo{grupo.equipos.size === 1 ? '' : 's'}</span>
        <span>{grupo.software || 'Software no indicado'}</span>
        {grupo.maquina_virtual && <span>VM: {grupo.maquina_virtual}</span>}
        {grupo.tarea && <span>Tarea: {grupo.tarea}</span>}
      </div>
      <strong>{grupo.desc_error || grupo.clasificacion_evidencia || 'Error sin descripción'}</strong>
      {grupo.clasificacion_evidencia && grupo.clasificacion_evidencia !== grupo.desc_error && <small>{grupo.clasificacion_evidencia}</small>}
    </div>
    <div className="clasificacion-divider" />
    <div className="clasificacion-controles">
      <label>
        <span>Clasificar como</span>
        <select value={estado} disabled={ocupado} onChange={(event) => setEstado(event.target.value)}>
          {TIPOS.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </label>
      <label>
        <span>Código</span>
        <input value={codigo} disabled={ocupado || estado === 'OK'} onChange={(event) => setCodigo(event.target.value)}
          placeholder={estado === 'OK' ? 'No aplica' : 'Ej.: ERROR_DESTINO'} />
      </label>
      {estado === 'ERROR' && <label>
        <span>Nivel</span>
        <select value={severidad} disabled={ocupado} onChange={(event) => setSeveridad(event.target.value)}>
          {NIVELES_ERROR.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
      </label>}
      <label className="clasificacion-descripcion">
        <span>Descripción</span>
        <textarea rows="5" value={descripcion} disabled={ocupado || estado === 'OK'} onChange={(event) => setDescripcion(event.target.value)}
          placeholder={estado === 'OK' ? 'Se limpiará el error' : 'Motivo que quedará en el historial'} />
      </label>
      <button className="btn" type="button" disabled={ocupado || (estado !== 'OK' && !descripcion.trim())} onClick={enviar}>
        {ocupado ? 'Clasificando...' : 'Aplicar'}
      </button>
    </div>
  </article>;
}

export default function ClasificacionPendiente({ copias, onClasificar, onFiltrar, modo = 'bloque' }) {
  const grupos = useMemo(() => {
    const mapa = new Map();
    (copias || []).filter((c) => c.estado === 'REVISION').forEach((c) => {
      const key = claveGrupo(c);
      const actual = mapa.get(key);
      if (actual) {
        actual.total += 1;
        actual.equipos.add(c.equipo);
        if (String(c.fecha) > String(actual.fecha)) actual.fecha = c.fecha;
        return;
      }
      mapa.set(key, {
        ...grupoPayload(c),
        fecha: c.fecha,
        total: 1,
        equipos: new Set([c.equipo]),
      });
    });
    return [...mapa.values()].sort((a, b) => b.total - a.total || String(b.fecha).localeCompare(String(a.fecha)));
  }, [copias]);

  if (!grupos.length) return null;
  return <section className={`clasificacion-pendiente${modo === 'pagina' ? ' en-pagina' : ''}`} aria-labelledby="clasificacion-pendiente-titulo">
    {modo !== 'pagina' && <div className="clasificacion-heading">
      <div>
        <span className="eyebrow">Pendiente</span>
        <h2 id="clasificacion-pendiente-titulo">Errores pendientes de clasificación</h2>
      </div>
      {onFiltrar && <button className="btn sec" type="button" onClick={onFiltrar}>{grupos.length} grupo{grupos.length === 1 ? '' : 's'}</button>}
    </div>}
    {modo === 'pagina' && <div className="clasificacion-resumen">{grupos.length} grupo{grupos.length === 1 ? '' : 's'} por revisar</div>}
    <div className="clasificacion-lista">
      {grupos.map((grupo) => <GrupoPorClasificar key={claveGrupo(grupo)} grupo={grupo} onClasificar={onClasificar} />)}
    </div>
  </section>;
}
