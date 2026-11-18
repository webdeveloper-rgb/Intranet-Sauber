import { useEffect, useState } from 'react';
import { api } from '../api';
import { rutaHref } from '../routes';

export default function Inicio({ equipoId = null, sesion, refreshSignal = 0, onNavigate }) {
  const [equipos, setEquipos] = useState([]);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState('');
  const [actualizar, setActualizar] = useState(0);

  useEffect(() => {
    let activo = true;
    if (!sesion) return () => { activo = false; };

    setCargando(true);
    if (!equipoId) {
      api('panel_inicio').then((data) => {
        if (activo) { setEquipos(data.equipos || []); setError(''); }
      }).catch((e) => { if (activo) setError(e.message || 'No se pudo cargar el inicio.'); })
        .finally(() => { if (activo) setCargando(false); });
      return () => { activo = false; };
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
      <div className="page-heading">
        <div><span className="eyebrow">Ficha del equipo</span>
          <h2 id="inicio-titulo">Equipo #{equipoId}</h2>
          <p>Abriendo la ficha paginada del equipo…</p></div>
        <button className="btn sec" type="button" onClick={() => onNavigate('/')}>← Volver al inicio</button>
      </div>
      {error && <div className="errbox" role="alert">{error} <button className="btn sec" onClick={reintentar}>Reintentar</button></div>}
      {cargando && <div className="empty-state" role="status">Localizando equipo…</div>}
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
      {equipos.map((item) => {
        const grupo = item.cliente_id
          ? `/clientes/${encodeURIComponent(String(item.cliente_id))}`
          : '/clientes/sin-asignar';
        const destino = `${grupo}/equipos/${item.id}`;
        return <a className="inicio-equipo" key={item.id} href={rutaHref(destino)}
        onClick={(event) => { event.preventDefault(); onNavigate(destino); }}>
        <div className="inicio-equipo-titulo"><div><span className="eyebrow">{item.cliente || 'Sin asignar'}</span>
          <h3>{item.monitor_alias || item.nombre}</h3><p>{[item.software, item.programa, item.tipo === 'servidor' ? 'Servidor' : item.tipo === 'usuario' ? 'Equipo' : null].filter(Boolean).join(' · ')}</p></div>
          <span aria-hidden="true" className="inicio-equipo-flecha">›</span></div>
        <div className="inicio-equipo-conteos">
          <span className={Number(item.errores_pendientes) ? 'badge error' : 'badge'}>{item.errores_pendientes} errores de copia</span>
          <span className={Number(item.total_errores_evento) ? 'badge critical' : 'badge'}>{item.total_errores_evento} errores/eventos</span>
        </div>
        {item.errores_evento?.length > 0 && <p className="inicio-ultimo-error">Último evento: {item.errores_evento[0].titulo}</p>}
      </a>;
      })}
    </div> : <div className="empty-state"><strong>Todo al día</strong><span>No hay equipos con errores de copias ni eventos de Windows críticos o de error.</span></div>}
  </section>;
}
