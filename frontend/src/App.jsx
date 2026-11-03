import { useState, useMemo, useEffect, useCallback, useReducer } from 'react';
import { iniciarSesion } from './api';
import { useCopias } from './hooks/useCopias';
import { TEST, norm, contadores, repeticiones, claveEquipo } from './logic';
import Header from './components/Header';
import Kpis from './components/Kpis';
import Filtros from './components/Filtros';
import Tabla from './components/Tabla';
import Detalle from './components/Detalle';
import Usuarios from './components/Usuarios';

const F0 = { cli: '', desde: '', hasta: '', horas: 24 };

export default function App() {
  const [sesion, setSesion] = useState(null);
  const copias = useCopias();
  const [filtro, setFiltro] = useState('todos');
  const [f, setF] = useReducer((s, p) => ({ ...s, ...p }), F0);
  const [sel, setSel] = useState(null);
  const [verUsuarios, setVerUsuarios] = useState(false);

  useEffect(() => { iniciarSesion().then(setSesion).catch(() => {}); }, []);

  const { data } = copias;
  const n = useMemo(() => contadores(data, f.horas), [data, f.horas]);
  const rep = useMemo(() => repeticiones(data), [data]);
  const rows = useMemo(() => {
    const q = norm(f.cli.trim());
    return data.filter((c) =>
      TEST[filtro](c, f.horas) &&
      (!q || norm(c.cliente).includes(q)) &&
      (!f.desde || c.fecha.slice(0, 10) >= f.desde) &&
      (!f.hasta || c.fecha.slice(0, 10) <= f.hasta));
  }, [data, filtro, f]);

  const abrir = useCallback((id) => setSel(id), []);
  const cerrar = useCallback(() => setSel(null), []);
  const cur = data.find((c) => c.id === sel);
  const hist = cur
    ? data.filter((x) => claveEquipo(x) === claveEquipo(cur) && x.id !== cur.id)
        .sort((a, b) => b.fecha.localeCompare(a.fecha))
    : [];

  return (
    <>
      <Header nombre={sesion ? sesion.nombre : ''} rol={sesion?.rol} onUsuarios={() => setVerUsuarios(true)} />
      <main>
        {copias.fallo && (
          <div className="errbox">
            No se pudieron cargar los datos: {copias.fallo}{' '}
            <button className="btn sec" onClick={copias.cargar}>Reintentar</button>
          </div>
        )}
        <Kpis n={n} total={data.length} />
        <Filtros filtro={filtro} setFiltro={setFiltro} n={n} f={f} setF={setF} resultados={rows.length} />
        <Tabla rows={rows} cargando={copias.cargando} onOpen={abrir} rep={rep} horas={f.horas} claveEquipo={claveEquipo} />
        <p className="note">Pulsa una fila para ver el detalle. Los datos se actualizan solos cada minuto.</p>
      </main>
      {verUsuarios && sesion && <Usuarios sesion={sesion} onClose={() => setVerUsuarios(false)} />}
      {cur && (
        <Detalle c={cur} onClose={cerrar} acciones={copias} hist={hist} r={rep[claveEquipo(cur)] || 0} />
      )}
    </>
  );
}
