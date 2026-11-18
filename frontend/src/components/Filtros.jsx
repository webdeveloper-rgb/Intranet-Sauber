/**
 * Controles de filtro y rango temporal de la lista de copias.
 */
import { FILTROS, EXTRA, ALERTA, PROGRAMAS } from '../logic';
import FiltrosActivos from './FiltrosActivos';

export default function Filtros({ filtro, setFiltro, n, f, setF, resultados, total, softwares = [] }) {
  const { cli, tipo, software, desde, hasta, orden, horas } = f;
  const filtroEstado = FILTROS.find(([clave]) => clave === filtro)?.[1] || filtro;
  const filtrosActivos = [
    ...(filtro !== 'todos' ? [{ clave: 'estado', etiqueta: `Estado: ${filtroEstado}` }] : []),
    ...(cli ? [{ clave: 'cli', etiqueta: `Búsqueda: ${cli}` }] : []),
    ...(tipo ? [{ clave: 'tipo', etiqueta: `Tipo: ${tipo.startsWith('programa:') ? tipo.slice(9) : tipo}` }] : []),
    ...(software ? [{ clave: 'software', etiqueta: `Software: ${software}` }] : []),
    ...(desde ? [{ clave: 'desde', etiqueta: `Desde: ${desde}` }] : []),
    ...(hasta ? [{ clave: 'hasta', etiqueta: `Hasta: ${hasta}` }] : []),
  ];
  const quitarFiltro = (clave) => {
    if (clave === 'estado') setFiltro('todos');
    else setF({ [clave]: '' });
  };
  const limpiarFiltros = () => {
    setFiltro('todos');
    setF({ cli: '', tipo: '', software: '', desde: '', hasta: '' });
  };
  const filtroAlerta = ALERTA[filtro] && n[EXTRA[filtro]] > 0;
  const alertas = (n.sc || 0) + (n.a || 0);
  return (
      <div className="fil">
        <label className="fil-estado" htmlFor="filtro-copias">
          <span>Estado</span>
          <select id="filtro-copias" aria-label="Filtrar copias por estado"
          className={filtroAlerta ? 'al' : ''}
          value={filtro} onChange={(e) => setFiltro(e.target.value)}>
            {FILTROS.map(([k, t]) => (
              <option key={k} value={k}>
                {t}{EXTRA[k] ? ` (${n[EXTRA[k]]})` : ''}
              </option>
            ))}
          </select>
        </label>
        <span className={`aviso-cuenta${alertas ? ' activa' : ''}`}
          title={`Avisos: ${n.sc || 0} sin copia y ${n.a || 0} errores atrasados`}
          aria-label={`Avisos: ${alertas}`}>
          Avisos <strong>{alertas}</strong>
        </span>
        <input type="search" placeholder="Cliente, equipo, software o error…" aria-label="Buscar copias" value={cli}
          onChange={(e) => setF({ cli: e.target.value })} />
        <label>Tipo de equipo{' '}
          <select value={tipo} onChange={(e) => setF({ tipo: e.target.value })}>
            <option value="">Todos</option>
            <option value="usuario">Usuarios</option>
            <option value="servidor">Servidores</option>
            <option value="programa">Programas (todos)</option>
            {PROGRAMAS.map((p) => <option key={p} value={'programa:' + p}>{p}</option>)}
          </select>
        </label>
        <label>Software de copia{' '}
          <select value={software} onChange={(e) => setF({ software: e.target.value })}>
            <option value="">Todos</option>
            {softwares.map((nombre) => <option key={nombre} value={nombre}>{nombre}</option>)}
          </select>
        </label>
        <label>Fecha desde <input type="date" value={desde} max={hasta || undefined}
          onChange={(e) => setF({ desde: e.target.value })} /></label>
        <label>Fecha hasta <input type="date" value={hasta} min={desde || undefined}
          onChange={(e) => setF({ hasta: e.target.value })} /></label>
        <span className="cnt">{resultados}{Number.isFinite(total) ? ` de ${total}` : ''} resultado${resultados === 1 ? '' : 's'}</span>
        <label>Ordenar{' '}
          <select value={orden} onChange={(e) => setF({ orden: e.target.value })}>
            <option value="fecha-desc">Fecha: recientes primero</option>
            <option value="fecha-asc">Fecha: antiguas primero</option>
            <option value="cliente-az">Cliente: A a Z</option>
            <option value="cliente-za">Cliente: Z a A</option>
            <option value="equipo-az">Equipo: A a Z</option>
            <option value="equipo-za">Equipo: Z a A</option>
          </select>
        </label>
        <label>Avisar errores tras{' '}
          <select value={horas} onChange={(e) => setF({ horas: +e.target.value })}>
            {[12, 24, 48, 72].map((h) => <option key={h} value={h}>{h} h</option>)}
          </select>
        </label>
        <FiltrosActivos filtros={filtrosActivos} onQuitar={quitarFiltro} onLimpiar={limpiarFiltros} />
      </div>
  );
}
