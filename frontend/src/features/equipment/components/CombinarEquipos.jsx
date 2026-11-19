import { useEffect, useMemo, useState } from 'react';
import { api } from '../../../shared/api/client';
import { norm } from '../../../shared/lib/logic';

export default function CombinarEquipos({ equipo, equipos, onSaved }) {
  const [destinoId, setDestinoId] = useState(String(equipo.id));
  const [seleccion, setSeleccion] = useState([]);
  const [busqueda, setBusqueda] = useState('');
  const [plan, setPlan] = useState(null);
  const [agenteId, setAgenteId] = useState('');
  const [confirmado, setConfirmado] = useState(false);
  const [ocupado, setOcupado] = useState(false);
  const [error, setError] = useState('');
  const [resultado, setResultado] = useState('');
  const destino = equipos.find((item) => String(item.id) === destinoId);
  useEffect(() => {
    setDestinoId(String(equipo.id)); setSeleccion([]); setPlan(null); setConfirmado(false); setError('');
  }, [equipo.id]);
  const candidatos = useMemo(() => equipos.filter((item) => String(item.id) !== destinoId
    && (!busqueda.trim() || norm(`${item.nombre} ${item.cliente || ''} ${item.software}`).includes(norm(busqueda.trim())))),
  [equipos, destinoId, busqueda]);
  const invalidar = () => { setPlan(null); setConfirmado(false); setError(''); setResultado(''); };
  const revisar = async () => {
    setOcupado(true); invalidar();
    try {
      const preview = await api('equipo_combinar_prever', { destino_id: Number(destinoId), origen_ids: seleccion });
      setPlan(preview);
      setAgenteId(preview.agentes?.length === 1 ? String(preview.agentes[0].equipo_id) : '');
    }
    catch (e) { setError(e.message); }
    finally { setOcupado(false); }
  };
  const combinar = async () => {
    setOcupado(true); setError('');
    try {
      const response = await api('equipo_combinar', { destino_id: plan.destino.id, origen_ids: plan.origenes.map((item) => item.id), token: plan.token,
        agente_equipo_id: agenteId ? Number(agenteId) : null });
      setPlan(null); setSeleccion([]); setConfirmado(false);
      setResultado(`${response.combinados} equipo(s) combinados en ${plan.destino.nombre}.`);
      await onSaved(plan.destino);
    } catch (e) { setError(e.message); setPlan(null); setConfirmado(false); }
    finally { setOcupado(false); }
  };
  return <section className="equipo-combinar" aria-labelledby="titulo-combinar">
    <h3 id="titulo-combinar">Combinar equipos duplicados</h3>
    <p>Reúne las fichas que pertenecen al mismo equipo. El destino conserva su nombre, cliente y configuración de avisos; los nombres anteriores seguirán recibiendo copias en esa ficha.</p>
    <fieldset disabled={ocupado}>
      <label htmlFor="combinar-destino">Equipo que se conserva</label>
      <select id="combinar-destino" value={destinoId} onChange={(e) => { setDestinoId(e.target.value); setSeleccion([]); invalidar(); }}>
        {equipos.map((item) => <option key={item.id} value={item.id}>{item.nombre} · {item.cliente || 'Sin asignar'} · {item.software}</option>)}
      </select>
      <label htmlFor="combinar-busqueda">Buscar equipos de origen</label>
      <input id="combinar-busqueda" type="search" value={busqueda} onChange={(e) => setBusqueda(e.target.value)} placeholder="Nombre, cliente o software" />
      <div className="combinar-lista" role="group" aria-label="Equipos que se trasladan">
        {candidatos.map((item) => {
          const otroCliente = item.cliente_id && Number(item.cliente_id) !== Number(destino?.cliente_id);
          return <label key={item.id} className="combinar-origen">
            <input type="checkbox" checked={seleccion.includes(item.id)} disabled={Boolean(otroCliente) || (!seleccion.includes(item.id) && seleccion.length >= 20)}
              onChange={(e) => { setSeleccion((prev) => e.target.checked ? [...prev, item.id] : prev.filter((id) => id !== item.id)); invalidar(); }} />
            <span><strong>{item.nombre}</strong><small>{item.cliente || 'Sin asignar'} · {item.software}{item.tiene_monitor ? ' · Con agente' : ''}{otroCliente ? ' · Otro cliente: cambia su asignación primero' : ''}</small></span>
          </label>;
        })}
        {!candidatos.length && <p>No hay equipos con esa búsqueda.</p>}
      </div>
      <button type="button" className="btn sec" onClick={revisar} disabled={ocupado || !seleccion.length}>Revisar combinación ({seleccion.length})</button>
    </fieldset>
    {plan && <div className="combinar-preview">
      <h4>Destino: {plan.destino.nombre}</h4>
      <ul>{plan.origenes.map((item) => <li key={item.id}><strong>{item.nombre}</strong>: {item.copias} copias, {item.pendientes} avisos recibidos y {item.eventos} eventos.</li>)}</ul>
      {!!plan.agentes?.length && <fieldset disabled={ocupado} className="combinar-agentes">
        <legend>Agente que se conserva</legend>
        <p>Elige el instalado actualmente en el equipo. Los demás se desactivarán y sus credenciales y tokens dejarán de servir; su historial se conserva.</p>
        {plan.agentes.map((agent) => <label className="combinar-origen" key={agent.equipo_id}>
          <input type="radio" name="combinar-agente" value={agent.equipo_id} checked={agenteId === String(agent.equipo_id)}
            onChange={(e) => { setAgenteId(e.target.value); setConfirmado(false); }} />
          <span><strong>{agent.nombre}</strong><small>ID: {agent.machine_id}</small>
            <small>Última conexión: {agent.ultima_conexion || 'Sin informes'}</small>
            <small>{[agent.hostname, agent.sistema, agent.modelo].filter(Boolean).join(' · ') || 'Sin datos de hardware'}</small>
          </span>
        </label>)}
      </fieldset>}
      <p>Las notas y revisiones de cada copia se conservan. Las fichas de origen desaparecerán del listado. No hay una opción de deshacer en el panel.</p>
      <label className="combinar-confirmacion"><input type="checkbox" checked={confirmado} disabled={ocupado} onChange={(e) => setConfirmado(e.target.checked)} />Confirmo que son el mismo equipo físico y he revisado el destino.</label>
      <button type="button" className="btn" disabled={ocupado || !confirmado || (plan.agentes?.length > 0 && !agenteId)} onClick={combinar}>{ocupado ? 'Combinando…' : 'Confirmar y combinar'}</button>
    </div>}
    {error && <p className="errbox" role="alert">{error}</p>}
    {resultado && <p role="status">{resultado}</p>}
    {!!equipo.nombres_recepcion?.length && <details><summary>Nombres vinculados ({equipo.nombres_recepcion.length})</summary>
      <ul>{equipo.nombres_recepcion.map((item) => <li key={`${item.software}|${item.nombre}`}>{item.nombre} · {item.software}</li>)}</ul>
    </details>}
  </section>;
}
