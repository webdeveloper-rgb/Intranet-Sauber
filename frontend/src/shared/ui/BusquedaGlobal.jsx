import { useEffect, useState, useMemo } from 'react';
import { api } from '../api/client';
import { norm } from '../lib/logic';

export default function BusquedaGlobal({ onNavigate }) {
  const [abierto, setAbierto] = useState(false);
  const [q, setQ] = useState('');
  const [equipos, setEquipos] = useState(null);

  useEffect(() => {
    const handler = (e) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setAbierto(true);
      }
      if (e.key === 'Escape') setAbierto(false);
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, []);

  useEffect(() => {
    if (!abierto || equipos) return;
    api('equipos').then((r) => setEquipos(r.equipos || [])).catch(() => setEquipos([]));
  }, [abierto, equipos]);

  const resultados = useMemo(() => {
    if (!equipos || !q.trim()) return [];
    const nq = norm(q.trim());
    return equipos.filter((e) => norm(`${e.nombre} ${e.cliente || ''} ${e.software} ${e.programa || ''}`).includes(nq)).slice(0, 12);
  }, [equipos, q]);

  if (!abierto) return null;
  return (
    <div className="busqueda-global-overlay" onClick={() => setAbierto(false)}>
      <div className="busqueda-global" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal="true" aria-label="Búsqueda global">
        <input autoFocus type="search" value={q} onChange={(e) => setQ(e.target.value)}
          placeholder="Buscar equipo, cliente o software…" aria-label="Búsqueda global" />
        <ul className="busqueda-global__results">
          {resultados.length === 0 && q.trim() && <li className="busqueda-global__empty">Sin resultados</li>}
          {resultados.map((e) => (
            <li key={e.id}>
              <button onClick={() => { setAbierto(false); onNavigate(`/clientes/${e.cliente_id || 'sin-asignar'}/equipos/${e.id}`); }}>
                <strong>{e.nombre}</strong>
                <small>{e.cliente || 'Sin asignar'} · {e.software}</small>
              </button>
            </li>
          ))}
        </ul>
        <div className="busqueda-global__hint">Ctrl+K para abrir · Esc para cerrar</div>
      </div>
    </div>
  );
}