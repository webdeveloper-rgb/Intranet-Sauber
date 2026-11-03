import { useState, useEffect, useCallback, useMemo } from 'react';
import { api } from '../api';
import { norm } from '../logic';

const unir = (...v) => v.filter(Boolean).join(' · ');
const textoMaquina = (m) => norm(unir(m.codigo, m.nombre, m.marca, m.matricula));

function Maquinas({ lista }) {
  if (!lista.length) return <div className="vacio">Este cliente no tiene máquinas.</div>;
  return (
    <div className="wrap">
      <table className="mq">
        <thead>
          <tr><th>Código</th><th>Nombre</th><th>Marca</th><th>Matrícula / Serie</th></tr>
        </thead>
        <tbody>
          {lista.map((m) => (
            <tr key={m.id} style={{ cursor: 'default' }}>
              <td className="nowrap">{m.codigo || '—'}</td>
              <td>{m.nombre || '—'}</td>
              <td>{m.marca || '—'}</td>
              <td>{m.matricula || '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

export default function Clientes({ sesion }) {
  const admin = sesion?.rol === 'admin';
  const [cat, setCat] = useState(null);
  const [q, setQ] = useState('');
  const [abierto, setAbierto] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [sync, setSync] = useState(false);
  const [fallo, setFallo] = useState('');
  const [resumen, setResumen] = useState(null);

  const cargar = useCallback(async () => {
    setCargando(true);
    try {
      setCat(await api('catalogo'));
      setFallo('');
    } catch (e) {
      setFallo(e.message);
    } finally {
      setCargando(false);
    }
  }, []);
  useEffect(() => { cargar(); }, [cargar]);

  const sincronizar = async () => {
    setSync(true);
    try {
      setResumen(await api('sync', {}));
      await cargar();
    } catch (e) {
      setFallo(e.message);
    }
    setSync(false);
  };

  const filtro = norm(q.trim());
  const lista = useMemo(() => {
    if (!cat) return [];
    if (!filtro) return cat.clientes;
    return cat.clientes.filter((c) =>
      norm(unir(c.codigo, c.nombre, c.cif, c.email, c.localidad)).includes(filtro) ||
      c.maquinas.some((m) => textoMaquina(m).includes(filtro)));
  }, [cat, filtro]);

  const u = cat?.ultima;
  return (
    <>
      <div className="fil">
        <input type="search" placeholder="Buscar cliente, CIF, máquina o matrícula…" aria-label="Buscar cliente"
          value={q} onChange={(e) => setQ(e.target.value)} />
        <span className="cnt">{lista.length} cliente{lista.length === 1 ? '' : 's'}</span>
        {admin && (
          <button className="btn" disabled={sync} onClick={sincronizar}>
            {sync ? 'Sincronizando…' : '🔄 Sincronizar ahora'}
          </button>
        )}
      </div>

      {fallo && <div className="errbox">{fallo}{' '}<button className="btn sec" onClick={cargar}>Reintentar</button></div>}
      {cat && !cat.configurado && (
        <div className="errbox">La API no está configurada en <code>config/config.php</code> (sección <code>api_sync</code>).</div>
      )}
      {u && u.ok === 0 && (
        <div className="errbox">La última sincronización falló ({u.fecha}): {u.mensaje}</div>
      )}
      {resumen && (
        <div className="okmsg">
          Sincronizado: {resumen.clientes} clientes y {resumen.maquinas} máquinas
          {resumen.omitidos ? ` (${resumen.omitidos} registros sin identificador omitidos)` : ''}.
          {resumen.campos && (
            <details>
              <summary>Campos que devuelve la API</summary>
              <p><b>Cliente:</b> {resumen.campos.clientes.join(', ') || '—'}</p>
              <p><b>Máquina:</b> {resumen.campos.maquinas.join(', ') || '—'}</p>
            </details>
          )}
        </div>
      )}

      {lista.map((c) => {
        const coincideMaq = !!filtro && c.maquinas.some((m) => textoMaquina(m).includes(filtro));
        const open = abierto === c.id || coincideMaq;
        return (
          <div key={c.id} className={'cli' + (open ? ' open' : '')}>
            <button onClick={() => setAbierto(abierto === c.id ? null : c.id)} aria-expanded={open}>
              <span className="caret">▸</span>
              <span className="nom">{c.nombre || '(sin nombre)'}</span>
              <span className="sub">{c.codigo}</span>
              <span className="nb">{c.maquinas.length} máquina{c.maquinas.length === 1 ? '' : 's'}</span>
            </button>
            {open && (
              <div className="det">
                <p className="dat">{unir(c.cif, c.telefono, c.email, [c.localidad, c.provincia].filter(Boolean).join(', ')) || 'Sin más datos'}</p>
                <Maquinas lista={c.maquinas} />
              </div>
            )}
          </div>
        );
      })}

      {!lista.length && (
        <div className="wrap"><div className="vacio">
          {cargando ? 'Cargando…' : cat?.clientes.length ? 'Sin resultados' : 'Todavía no hay clientes sincronizados'}
        </div></div>
      )}

      {cat && cat.sin_cliente.length > 0 && !filtro && (
        <section className="notas">
          <h3>Máquinas sin cliente asignado ({cat.sin_cliente.length})</h3>
          <Maquinas lista={cat.sin_cliente} />
        </section>
      )}

      <p className="note">
        {u ? `Última sincronización: ${u.fecha}${u.ok === 1 ? ' (correcta)' : u.ok === 0 ? ' (con error)' : ''}. ` : ''}
        {cat?.auto_minutos ? `Se actualiza sola cada ${cat.auto_minutos} min mientras alguien usa el panel.` : ''}
      </p>
    </>
  );
}