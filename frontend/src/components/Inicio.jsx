import { useEffect, useState } from 'react';
import { api } from '../api';
import { rutaHref } from '../routes';

function fechaHora(value) {
  if (!value) return 'Fecha desconocida';
  const date = new Date(String(value).replace(' ', 'T'));
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('es-ES', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function consultarNotificaciones(equipoId, nivel) {
  return api(`notificaciones&page=1&q=&tipo=all&nivel=${nivel}&por_pagina=500&equipo_id=${equipoId}`)
    .then(async (first) => {
      const rows = [...(first.notificaciones || [])];
      const pages = Math.ceil(Number(first.total || 0) / Number(first.por_pagina || 500));
      for (let page = 2; page <= pages; page += 1) {
        const next = await api(`notificaciones&page=${page}&q=&tipo=all&nivel=${nivel}&por_pagina=500&equipo_id=${equipoId}`);
        rows.push(...(next.notificaciones || []));
      }
      return rows;
    });
}

export default function Inicio({ equipoId = null, sesion, refreshSignal = 0, onNavigate }) {
  const [equipos, setEquipos] = useState([]);
  const [copias, setCopias] = useState([]);
  const [eventos, setEventos] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState('');
  const [actualizar, setActualizar] = useState(0);
  const equipo = equipos.find((item) => Number(item.id) === Number(equipoId));

  useEffect(() => {
    let activo = true;
    if (!sesion) return () => { activo = false; };
    if (!equipoId) {
      setCargando(true);
      api('panel_inicio').then((data) => {
        if (activo) { setEquipos(data.equipos || []); setError(''); }
      }).catch((e) => { if (activo) setError(e.message || 'No se pudo cargar el inicio.'); })
        .finally(() => { if (activo) setCargando(false); });
      return () => { activo = false; };
    }

    setCargando(true);
    Promise.all([
      api('equipos'),
      api('equipo_errores', { equipo_id: equipoId }),
      consultarNotificaciones(equipoId, 'error'),
      consultarNotificaciones(equipoId, 'critico'),
    ]).then(([listado, errores, eventosError, eventosCriticos]) => {
      if (!activo) return;
      const todos = [...(listado.equipos || [])];
      setEquipos(todos);
      setCopias(errores.errores || []);
      setEventos([...eventosError, ...eventosCriticos].filter((item) => item.tipo !== 'copias_repetidas')
        .sort((a, b) => String(b.fecha).localeCompare(String(a.fecha))));
      setError('');
    }).catch((e) => { if (activo) setError(e.message || 'No se pudieron cargar los errores del equipo.'); })
      .finally(() => { if (activo) setCargando(false); });
    return () => { activo = false; };
  }, [equipoId, sesion, refreshSignal, actualizar]);

  const reintentar = () => {
    setError('');
    setActualizar((valor) => valor + 1);
  };

  if (equipoId) {
    return <section className="inicio-page" aria-labelledby="inicio-titulo">
      <div className="page-heading">
        <div><span className="eyebrow">Incidencias del equipo</span>
          <h2 id="inicio-titulo">{equipo?.monitor_alias || equipo?.nombre || `Equipo #${equipoId}`}</h2>
          <p>Solo se muestran fallos y eventos clasificados como error o crítico.</p></div>
        <button className="btn sec" type="button" onClick={() => onNavigate('/')}>← Volver al inicio</button>
      </div>
      {error && <div className="errbox" role="alert">{error} <button className="btn sec" onClick={reintentar}>Reintentar</button></div>}
      {cargando ? <div className="empty-state" role="status">Cargando errores del equipo…</div> : <>
        <section className="incidencias-seccion">
          <div className="section-heading"><div><span className="eyebrow">Copias</span><h3>Errores de copias</h3></div>
            <span className="incidencia-count error">{copias.length}{copias.length === 50 ? '+' : ''}</span></div>
          {copias.length ? <div className="incidencia-lista">{copias.map((copy) => <article className="incidencia-item" key={copy.copia_id ? `c-${copy.copia_id}` : `s-${copy.staging_id}`}>
            <div className="incidencia-meta"><time>{copy.fecha}</time><span>{copy.software || equipo?.software}</span>
              {copy.codigo && <code>{copy.codigo}</code>}{copy.staging === 1 && <span className="badge aviso">Pendiente de importar</span>}</div>
            <strong>{copy.desc_error || 'Error sin descripción'}</strong>
            {copy.clasificacion_evidencia && <p>Evidencia: {copy.clasificacion_evidencia}</p>}
          </article>)}</div> : <div className="empty-state compact">No hay errores de copia pendientes para este equipo.</div>}
          {Number(equipo?.errores_pendientes || 0) > copias.length && <p className="mut">Se muestran las 50 incidencias más recientes de copia.</p>}
        </section>
        <section className="incidencias-seccion">
          <div className="section-heading"><div><span className="eyebrow">Monitor de equipos</span><h3>Errores y eventos críticos</h3></div>
            <span className="incidencia-count error">{eventos.length}</span></div>
          {eventos.length ? <div className="incidencia-lista">{eventos.map((item) => <article className="incidencia-item" key={item.id}>
            <div className="incidencia-meta"><time>{fechaHora(item.fecha)}</time><span>{item.nivel === 'critico' ? 'Crítico' : 'Error'}</span><span>{item.tipo === 'salud' || item.tipo === 'disco' || item.tipo === 'espacio' ? 'Salud del equipo' : 'Evento de Windows'}</span></div>
            <strong>{item.titulo}</strong><p>{item.detalle || 'Sin detalle disponible.'}</p>
          </article>)}</div> : <div className="empty-state compact">No hay errores ni eventos críticos pendientes.</div>}
        </section>
      </>}
    </section>;
  }

  const totalCopias = equipos.reduce((n, item) => n + Number(item.errores_pendientes || 0), 0);
  const totalEventos = equipos.reduce((n, item) => n + Number(item.total_errores_evento || 0), 0);
  return <section className="inicio-page" aria-labelledby="inicio-titulo">
    <div className="page-heading">
      <div><span className="eyebrow">Centro de operaciones</span><h2 id="inicio-titulo">Estado de los equipos</h2>
        <p>Incidencias pendientes de copias y eventos de Windows clasificados como error o crítico.</p></div>
      <button className="btn sec" type="button" onClick={reintentar} disabled={cargando}>Actualizar</button>
    </div>
    <div className="inicio-resumen">
      <article className="inicio-metrica error"><span>Errores de copias</span><strong>{totalCopias}</strong></article>
      <article className="inicio-metrica critical"><span>Errores y eventos críticos</span><strong>{totalEventos}</strong></article>
      <article className="inicio-metrica"><span>Equipos afectados</span><strong>{equipos.length}</strong></article>
    </div>
    {error && <div className="errbox" role="alert">{error} <button className="btn sec" onClick={reintentar}>Reintentar</button></div>}
    {cargando ? <div className="empty-state" role="status">Cargando el estado de los equipos…</div> : equipos.length ? <div className="inicio-equipos">
      {equipos.map((item) => <a className="inicio-equipo" key={item.id} href={rutaHref(`/equipos/${item.id}`)}
        onClick={(event) => { event.preventDefault(); onNavigate(`/equipos/${item.id}`); }}>
        <div className="inicio-equipo-titulo"><div><span className="eyebrow">{item.cliente || 'Sin asignar'}</span>
          <h3>{item.monitor_alias || item.nombre}</h3><p>{[item.software, item.programa, item.tipo === 'servidor' ? 'Servidor' : item.tipo === 'usuario' ? 'Equipo' : null].filter(Boolean).join(' · ')}</p></div>
          <span aria-hidden="true" className="inicio-equipo-flecha">›</span></div>
        <div className="inicio-equipo-conteos">
          <span className={Number(item.errores_pendientes) ? 'badge error' : 'badge'}>{item.errores_pendientes} errores de copia</span>
          <span className={Number(item.total_errores_evento) ? 'badge critical' : 'badge'}>{item.total_errores_evento} errores/eventos</span>
        </div>
        {item.errores_evento?.length > 0 && <p className="inicio-ultimo-error">Último evento: {item.errores_evento[0].titulo}</p>}
      </a>)}
    </div> : <div className="empty-state"><strong>Todo al día</strong><span>No hay equipos con errores de copias ni eventos de Windows críticos o de error.</span></div>}
  </section>;
}
