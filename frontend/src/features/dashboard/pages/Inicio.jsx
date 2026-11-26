import { useEffect, useMemo, useRef, useState } from 'react';
import { api } from '../../../shared/api/client';
import { rutaHref } from '../../../app/routes';
import PageHeading from '../../../shared/ui/PageHeading';
import BarraFiltros from '../../../shared/ui/BarraFiltros';

const normalizar = (valor) => String(valor || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('es');

export default function Inicio({ equipoId = null, sesion, refreshSignal = 0, onNavigate }) {
  const [equipos, setEquipos] = useState([]);
  const tieneDatos = useRef(false);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState('');
  const [actualizar, setActualizar] = useState(0);
  const [busqueda, setBusqueda] = useState('');
  const [clienteFiltro, setClienteFiltro] = useState('todos');
  const [softwareFiltro, setSoftwareFiltro] = useState('todos');
  const [incidenciaFiltro, setIncidenciaFiltro] = useState('todos');
  const [orden, setOrden] = useState('incidencias');
  const etiquetasIncidencia = { copias: 'Errores de copias', eventos: 'Errores/eventos', ambos: 'Copias y eventos', 'en-curso': 'Copias en curso' };

  useEffect(() => {
    let activo = true;
    if (!sesion) return () => { activo = false; };

    if (!tieneDatos.current || equipoId) setCargando(true);
    if (!equipoId) {
      api('panel_inicio').then((data) => {
        if (activo) { setEquipos(data.equipos || []); tieneDatos.current = true; setError(''); }
      }).catch((e) => { if (activo) setError(e.message || 'No se pudo cargar el inicio.'); })
        .finally(() => { if (activo) setCargando(false); });
      const timer = setInterval(() => {
        api('panel_inicio').then((data) => {
          if (activo) setEquipos(data.equipos || []);
        }).catch(() => {});
      }, 60_000);
      return () => { activo = false; clearInterval(timer); };
    }

    // Los enlaces antiguos /equipos/:id se redirigen a la ficha paginada de Clientes.
    api('equipos').then((data) => {
      if (!activo) return;
      const equipo = (data.equipos || []).find((item) => Number(item.id) === Number(equipoId));
      if (!equipo) throw new Error('No se encontró el equipo solicitado.');
      const grupo = equipo.cliente_id
        ? `/clientes/${encodeURIComponent(String(equipo.cliente_id))}`
        : '/clientes/sin-asignar';
      onNavigate(`${grupo}/equipos/${equipo.id}`);
      setError('');
    }).catch((e) => { if (activo) setError(e.message || 'No se pudo abrir el equipo.'); })
      .finally(() => { if (activo) setCargando(false); });
    return () => { activo = false; };
  }, [equipoId, sesion, refreshSignal, actualizar, onNavigate]);

  const reintentar = () => {
    setError('');
    setActualizar((valor) => valor + 1);
  };

  if (equipoId) {
    return <section className="inicio-page" aria-labelledby="inicio-titulo">
      <PageHeading id="inicio-titulo" eyebrow="Ficha del equipo" title={`Equipo #${equipoId}`}
        description="Abriendo la ficha paginada del equipo…" />
      {error && <div className="errbox" role="alert">{error} <button className="btn sec" onClick={reintentar}>Reintentar</button></div>}
      {cargando && <div className="empty-state" role="status">Localizando equipo…</div>}
    </section>;
  }
  const totalCopias = equipos.reduce((n, item) => n + Number(item.errores_pendientes || 0), 0);
  const totalEventos = equipos.reduce((n, item) => n + Number(item.total_errores_evento || 0), 0);
  const equiposConErrores = equipos.filter((item) => Number(item.errores_pendientes || 0) > 0 || Number(item.total_errores_evento || 0) > 0).length;
  const totalCopiasEnCurso = equipos.reduce((n, item) => n + Number(item.copias_en_curso || 0), 0);
  const equiposConCopiaEnCurso = equipos.filter((item) => Number(item.copias_en_curso || 0) > 0).length;
  const sinAsignar = equipos.filter((item) => !item.cliente_id).length;
  const clientesAfectados = new Set(equipos.filter((item) => item.cliente_id).map((item) => String(item.cliente_id))).size;
  const clientes = useMemo(() => [...new Map(equipos.filter((item) => item.cliente_id)
    .map((item) => [String(item.cliente_id), item.cliente || `Cliente ${item.cliente_id}`])).entries()]
    .map(([id, nombre]) => ({ id, nombre }))
    .sort((a, b) => a.nombre.localeCompare(b.nombre, 'es', { sensitivity: 'base' })), [equipos]);
  const softwares = useMemo(() => [...new Set(equipos.map((item) => item.software).filter(Boolean))]
    .sort((a, b) => a.localeCompare(b, 'es', { sensitivity: 'base' })), [equipos]);
  const equiposFiltrados = useMemo(() => {
    const consulta = normalizar(busqueda.trim());
    return equipos.filter((item) => {
      const copias = Number(item.errores_pendientes || 0);
      const eventos = Number(item.total_errores_evento || 0);
      const enCurso = Number(item.copias_en_curso || 0);
      if (incidenciaFiltro === 'copias' && !copias) return false;
      if (incidenciaFiltro === 'eventos' && !eventos) return false;
      if (incidenciaFiltro === 'ambos' && (!copias || !eventos)) return false;
      if (incidenciaFiltro === 'en-curso' && !enCurso) return false;
      if (clienteFiltro === 'sin-asignar' && item.cliente_id) return false;
      if (clienteFiltro !== 'todos' && clienteFiltro !== 'sin-asignar'
        && String(item.cliente_id) !== clienteFiltro) return false;
      if (softwareFiltro !== 'todos' && item.software !== softwareFiltro) return false;
      if (consulta && !normalizar([
        item.nombre, item.cliente, item.software, item.programa,
        ...(item.productos_copia_en_curso || []),
        ...(item.errores_evento || []).map((evento) => evento.titulo),
      ].join(' ')).includes(consulta)) return false;
      return true;
    }).sort((a, b) => {
      const copiasA = Number(a.errores_pendientes || 0);
      const copiasB = Number(b.errores_pendientes || 0);
      const eventosA = Number(a.total_errores_evento || 0);
      const eventosB = Number(b.total_errores_evento || 0);
      const cursoA = Number(a.copias_en_curso || 0);
      const cursoB = Number(b.copias_en_curso || 0);
      if (orden === 'copias') return copiasB - copiasA || String(a.nombre).localeCompare(String(b.nombre), 'es');
      if (orden === 'eventos') return eventosB - eventosA || String(a.nombre).localeCompare(String(b.nombre), 'es');
      if (orden === 'en-curso') return cursoB - cursoA || String(a.nombre).localeCompare(String(b.nombre), 'es');
      if (orden === 'nombre') return String(a.nombre).localeCompare(String(b.nombre), 'es', { sensitivity: 'base' });
      return (copiasB + eventosB) - (copiasA + eventosA) || cursoB - cursoA || String(a.nombre).localeCompare(String(b.nombre), 'es');
    });
  }, [equipos, busqueda, clienteFiltro, softwareFiltro, incidenciaFiltro, orden]);
  const filtrosActivos = [
    ...(busqueda ? [{ clave: 'busqueda', etiqueta: `Búsqueda: ${busqueda}` }] : []),
    ...(incidenciaFiltro !== 'todos' ? [{ clave: 'incidencia', etiqueta: `Incidencia: ${etiquetasIncidencia[incidenciaFiltro]}` }] : []),
    ...(clienteFiltro !== 'todos' ? [{ clave: 'cliente', etiqueta: `Cliente: ${clienteFiltro === 'sin-asignar' ? 'Sin asignar' : clientes.find((cliente) => cliente.id === clienteFiltro)?.nombre || clienteFiltro}` }] : []),
    ...(softwareFiltro !== 'todos' ? [{ clave: 'software', etiqueta: `Programa: ${softwareFiltro}` }] : []),
  ];
  const quitarFiltro = (clave) => {
    if (clave === 'busqueda') setBusqueda('');
    else if (clave === 'incidencia') setIncidenciaFiltro('todos');
    else if (clave === 'cliente') setClienteFiltro('todos');
    else if (clave === 'software') setSoftwareFiltro('todos');
  };
  const limpiarFiltros = () => {
    setBusqueda('');
    setClienteFiltro('todos');
    setSoftwareFiltro('todos');
    setIncidenciaFiltro('todos');
    setOrden('incidencias');
  };
  return <section className="inicio-page" aria-labelledby="inicio-titulo">
    <PageHeading id="inicio-titulo" eyebrow="Sauber Ofimática" title="Estado de los equipos"
      description="Incidencias abiertas y copias en curso."
      actions={<button className="btn sec" type="button" onClick={reintentar} disabled={cargando}>Actualizar</button>} />
    <div className="inicio-resumen" aria-label="Métricas de incidencias">
      <article className="inicio-metrica"><span>Equipos con errores</span><strong>{equiposConErrores}</strong></article>
      <article className="inicio-metrica error"><span>Errores de copias</span><strong>{totalCopias}</strong></article>
      <article className="inicio-metrica running"><span>Copias en curso · {equiposConCopiaEnCurso} equipos</span><strong>{totalCopiasEnCurso}</strong></article>
      <article className="inicio-metrica critical"><span>Errores y eventos críticos</span><strong>{totalEventos}</strong></article>
      <article className="inicio-metrica"><span>Clientes afectados</span><strong>{clientesAfectados}</strong></article>
      <article className="inicio-metrica"><span>Equipos sin asignar</span><strong>{sinAsignar}</strong></article>
    </div>
    <BarraFiltros title="Filtrar equipos"
      search={{ id: 'inicio-busqueda', value: busqueda, placeholder: 'Equipo, cliente, software o evento',
        onChange: (event) => setBusqueda(event.target.value) }}
      fields={[
        { id: 'inicio-incidencia', label: 'Incidencia', type: 'select', value: incidenciaFiltro,
          onChange: (event) => setIncidenciaFiltro(event.target.value),
          options: [['todos', 'Todas'], ['copias', 'Errores de copias'], ['eventos', 'Errores/eventos'], ['ambos', 'Copias y eventos'], ['en-curso', 'Copias en curso']] },
        { id: 'inicio-cliente', label: 'Cliente', type: 'select', value: clienteFiltro,
          onChange: (event) => setClienteFiltro(event.target.value),
          options: [['todos', 'Todos los clientes'], ['sin-asignar', 'Sin asignar'], ...clientes.map((cliente) => [cliente.id, cliente.nombre])] },
        { id: 'inicio-programa', label: 'Programa', type: 'select', value: softwareFiltro,
          onChange: (event) => setSoftwareFiltro(event.target.value),
          options: [['todos', 'Todos los programas'], ...softwares.map((software) => [software, software])] },
        { id: 'inicio-orden', label: 'Ordenar por', type: 'select', value: orden,
          onChange: (event) => setOrden(event.target.value),
          options: [['incidencias', 'Más incidencias'], ['copias', 'Más errores de copias'], ['eventos', 'Más errores/eventos'], ['en-curso', 'Más copias en curso'], ['nombre', 'Nombre del equipo']] },
      ]}
      count={`${equiposFiltrados.length} de ${equipos.length} equipos`}
      activeFilters={filtrosActivos} onRemoveFilter={quitarFiltro} onClear={limpiarFiltros} />
    {error && <div className="errbox" role="alert">{error} <button className="btn sec" onClick={reintentar}>Reintentar</button></div>}
    {cargando ? <div className="empty-state" role="status">Cargando el estado de los equipos…</div> : equiposFiltrados.length ? <div className="inicio-equipos">
      {equiposFiltrados.map((item) => {
        const grupo = item.cliente_id
          ? `/clientes/${encodeURIComponent(String(item.cliente_id))}`
          : '/clientes/sin-asignar';
        const destino = `${grupo}/equipos/${item.id}`;
        const ultimoEvento = item.errores_evento?.[0]?.titulo;
        const productosEnCurso = item.productos_copia_en_curso || [];
        const tieneCopias = Number(item.errores_pendientes || 0) > 0;
        const tieneEventos = Number(item.total_errores_evento || 0) > 0;
        const tieneCurso = Number(item.copias_en_curso || 0) > 0;
        const prioridad = tieneCopias || tieneEventos ? 'error' : tieneCurso ? 'running' : 'normal';
        return <a className={`inicio-equipo prioridad-${prioridad}`} key={item.id} href={rutaHref(destino)}
        onClick={(event) => { event.preventDefault(); onNavigate(destino); }}>
        <div className="inicio-equipo-titulo"><div><span className="eyebrow">{item.cliente || 'Sin asignar'}</span>
          <h3>{item.nombre}</h3><p>{[item.software, item.programa, item.tipo === 'servidor' ? 'Servidor' : item.tipo === 'usuario' ? 'Equipo' : null].filter(Boolean).join(' · ')}</p></div>
          <span aria-hidden="true" className="inicio-equipo-flecha">›</span></div>
        <div className="inicio-equipo-conteos">
          <span className={tieneCopias ? 'badge error' : 'badge'}>Copias: {item.errores_pendientes}</span>
          <span className={tieneEventos ? 'badge critical' : 'badge'}>Eventos: {item.total_errores_evento}</span>
          {tieneCurso && <span className="badge running">En curso: {item.copias_en_curso}{productosEnCurso.length ? ` · ${productosEnCurso.join(', ')}` : ''}</span>}
        </div>
        {ultimoEvento ? <p className="inicio-ultimo-error">Último evento: {ultimoEvento}</p>
          : <span className="inicio-ultimo-error-placeholder" aria-hidden="true" />}
        <span className="inicio-equipo-accion">Revisar ficha</span>
      </a>;
      })}
    </div> : equipos.length ? <div className="empty-state"><strong>No hay equipos coincidentes</strong>
      <span>Ajusta los filtros para ver otros equipos con incidencias.</span>
      <button className="btn sec" type="button" onClick={limpiarFiltros}>Limpiar filtros</button></div>
      : <div className="empty-state"><strong>Todo al día</strong><span>No hay incidencias ni copias activas ahora mismo.</span></div>}
  </section>;
}
