import { useState, useEffect, useCallback, useMemo } from 'react';
import { api } from '../api';
import { norm } from '../logic';

const unir = (...v) => v.filter(Boolean).join(' · ');

export default function Clientes({ sesion }) {
  const admin = sesion?.rol === 'admin';
  const [cat, setCat] = useState(null);
  const [equipos, setEquipos] = useState([]);
  const [asignaciones, setAsignaciones] = useState({});
  const [q, setQ] = useState('');
  const [cargando, setCargando] = useState(true);
  const [sync, setSync] = useState(false);
  const [fallo, setFallo] = useState('');
  const [resumen, setResumen] = useState(null);
  const [correoSync, setCorreoSync] = useState(false);
  const [mensajeCorreo, setMensajeCorreo] = useState('');
  const [guardando, setGuardando] = useState(null);

  const cargar = useCallback(async () => {
    setCargando(true);
    try {
      const [c, e] = await Promise.all([api('catalogo'), api('equipos')]);
      setCat(c); setEquipos(e.equipos); setFallo('');
    }
    catch (e) { setFallo(e.message); }
    finally { setCargando(false); }
  }, []);
  useEffect(() => {
    cargar();
    const timer = setInterval(cargar, 60000);
    return () => clearInterval(timer);
  }, [cargar]);

  const sincronizar = async () => {
    setSync(true);
    try { setResumen(await api('sync', {})); await cargar(); }
    catch (e) { setFallo(e.message); }
    finally { setSync(false); }
  };

  const leerCorreo = async () => {
    setCorreoSync(true); setMensajeCorreo('');
    try {
      const r = await api('correo_sync', {});
      setMensajeCorreo(`Leídos ${r.leidas} avisos nuevos${r.omitidas ? `; ${r.omitidas} no reconocidos` : ''}.`);
      await cargar();
    } catch (e) { setFallo(e.message); }
    finally { setCorreoSync(false); }
  };

  const asignar = async (equipo) => {
    const cliente_id = Number(asignaciones[equipo.id] || equipo.cliente_id || 0);
    if (!cliente_id) return setFallo('Selecciona un cliente para asignar el equipo.');
    setGuardando(equipo.id); setFallo('');
    try {
      const r = await api('equipo_asignar', { id: equipo.id, cliente_id });
      setMensajeCorreo(`${equipo.nombre}: ${r.copias_importadas} copia${r.copias_importadas === 1 ? '' : 's'} añadida${r.copias_importadas === 1 ? '' : 's'} al panel.`);
      await cargar();
    } catch (e) { setFallo(e.message); }
    finally { setGuardando(null); }
  };

  const filtro = norm(q.trim());
  const lista = useMemo(() => {
    if (!cat) return [];
    return cat.clientes.filter((c) => !filtro || norm(unir(c.codigo, c.nombre, c.cif, c.email, c.localidad)).includes(filtro));
  }, [cat, filtro]);
  const u = cat?.ultima;

  return <>
    <div className="fil">
      <input type="search" placeholder="Buscar cliente, CIF o correo…" aria-label="Buscar cliente" value={q} onChange={(e) => setQ(e.target.value)} />
      <span className="cnt">{lista.length} cliente{lista.length === 1 ? '' : 's'}</span>
      {admin && <button className="btn" disabled={sync} onClick={sincronizar}>{sync ? 'Sincronizando…' : '🔄 Sincronizar clientes'}</button>}
      {admin && <button className="btn" disabled={correoSync} onClick={leerCorreo}>{correoSync ? 'Leyendo correo…' : '✉️ Leer avisos'}</button>}
    </div>
    {fallo && <div className="errbox">{fallo} <button className="btn sec" onClick={cargar}>Reintentar</button></div>}
    {cat && !cat.configurado && <div className="errbox">La API no está configurada en <code>config/config.php</code> (sección <code>api_sync</code>).</div>}
    {u && u.ok === 0 && <div className="errbox">La última sincronización falló ({u.fecha}): {u.mensaje}</div>}
    {resumen && <div className="okmsg">Sincronizados {resumen.clientes} clientes{resumen.omitidos ? ` (${resumen.omitidos} sin identificador, omitidos)` : ''}.</div>}
    {mensajeCorreo && <div className="okmsg">{mensajeCorreo}</div>}
    {lista.map((c) => <article key={c.id} className="cli">
      <div className="det">
        <strong className="nom">{c.nombre || '(sin nombre)'}</strong>
        {c.codigo && <span className="sub">{c.codigo}</span>}
        <p className="dat">{unir(c.cif, c.telefono, c.email, [c.localidad, c.provincia].filter(Boolean).join(', ')) || 'Sin más datos'}</p>
      </div>
    </article>)}
    {!lista.length && <div className="wrap"><div className="vacio">{cargando ? 'Cargando…' : cat?.clientes.length ? 'Sin resultados' : 'Todavía no hay clientes sincronizados'}</div></div>}
    {cat && <section className="notas">
      <h3>Equipos detectados en los correos ({equipos.length})</h3>
      <p className="note">Al leer el buzón, los avisos de Acronis e Iperius crean o actualizan su equipo. Al asignar un cliente, sus avisos pendientes se incorporan al panel de copias.</p>
      {!equipos.length && <div className="vacio">Todavía no se han detectado equipos en el correo.</div>}
      {equipos.map((equipo) => <div className="cli" key={equipo.id}>
        <div className="det">
          <strong className="nom">{equipo.nombre}</strong><span className="sub">{equipo.software}</span>
          <p className="dat">{equipo.cliente || 'Sin cliente asignado'} · {equipo.pendientes} copia{equipo.pendientes === 1 ? '' : 's'} pendiente{equipo.pendientes === 1 ? '' : 's'}</p>
          {admin && <div className="fil">
            <select aria-label={'Cliente de ' + equipo.nombre} value={asignaciones[equipo.id] ?? equipo.cliente_id ?? ''} onChange={(e) => setAsignaciones((a) => ({ ...a, [equipo.id]: e.target.value }))}>
              <option value="">Seleccionar cliente…</option>
              {cat.clientes.map((c) => <option key={c.id} value={c.id}>{c.nombre} {c.codigo ? `(${c.codigo})` : ''}</option>)}
            </select>
            <button className="btn" disabled={guardando === equipo.id} onClick={() => asignar(equipo)}>{guardando === equipo.id ? 'Guardando…' : 'Asignar cliente'}</button>
          </div>}
        </div>
      </div>)}
    </section>}
    <p className="note">{u ? `Última sincronización: ${u.fecha}${u.ok === 1 ? ' (correcta)' : u.ok === 0 ? ' (con error)' : ''}. ` : ''}{cat?.auto_minutos ? `Se actualiza sola cada ${cat.auto_minutos} min mientras alguien usa el panel.` : ''}</p>
  </>;
}
