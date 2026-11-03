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
import Clientes from './components/Clientes';

const fechaLocal = (fecha) => [fecha.getFullYear(), String(fecha.getMonth() + 1).padStart(2, '0'), String(fecha.getDate()).padStart(2, '0')].join('-');
const filtrosIniciales = () => {
  const hoy = new Date();
  const ayer = new Date(hoy);
  ayer.setDate(hoy.getDate() - 1);
  return { cli: '', desde: fechaLocal(ayer), hasta: fechaLocal(hoy), horas: 24 };
};

export default function App() {
  const [sesion, setSesion] = useState(null);
  const copias = useCopias();
  const [filtro, setFiltro] = useState('todos');
  const [f, setF] = useReducer((s, p) => ({ ...s, ...p }), undefined, filtrosIniciales);
  const [sel, setSel] = useState(null);
  const [verUsuarios, setVerUsuarios] = useState(false);
  const [vista, setVista] = useState('copias'); // 'copias' | 'clientes'
  const [paginaErrores, setPaginaErrores] = useState(1);

  useEffect(() => { iniciarSesion().then(setSesion).catch(() => {}); }, []);
  useEffect(() => { setPaginaErrores(1); }, [filtro, f.cli, f.desde, f.hasta]);

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
  const paginaErroresActiva = ['pend', 'rev', 'tras', 'venc', 'atr'].includes(filtro) && rows.length > 5;
  const totalPaginasErrores = Math.ceil(rows.length / 5);
  const paginaErroresActual = Math.min(paginaErrores, totalPaginasErrores || 1);
  const filasVisibles = paginaErroresActiva
    ? rows.slice((paginaErroresActual - 1) * 5, paginaErroresActual * 5)
    : rows;

  const abrir = useCallback((id) => setSel(id), []);
  const cerrar = useCallback(() => setSel(null), []);
  const cur = data.find((c) => c.id === sel);
  const hist = cur
    ? data.filter((x) => claveEquipo(x) === claveEquipo(cur) && x.id !== cur.id)
        .sort((a, b) => b.fecha.localeCompare(a.fecha))
    : [];

  return (
    <>
      <Header nombre={sesion ? sesion.nombre : ''} rol={sesion?.rol} vista={vista} onVista={setVista}
        onUsuarios={() => setVerUsuarios(true)} />
      <main>
        {vista === 'clientes' && sesion ? (
          <Clientes sesion={sesion} />
        ) : (
          <>
            {copias.fallo && (
              <div className="errbox">
                No se pudieron cargar los datos: {copias.fallo}{' '}
                <button className="btn sec" onClick={copias.cargar}>Reintentar</button>
              </div>
            )}
            <Kpis n={n} total={data.length} />
            <Filtros filtro={filtro} setFiltro={setFiltro} n={n} f={f} setF={setF} resultados={rows.length} />
            <Tabla rows={filasVisibles} cargando={copias.cargando} onOpen={abrir} rep={rep} horas={f.horas} claveEquipo={claveEquipo} />
            {paginaErroresActiva && <nav className="paginacion-clientes paginacion-errores" aria-label="Paginación de errores">
              <button className="btn sec" disabled={paginaErroresActual === 1} onClick={() => setPaginaErrores((p) => Math.max(1, p - 1))}>Anterior</button>
              <span>Errores {((paginaErroresActual - 1) * 5) + 1}–{Math.min(paginaErroresActual * 5, rows.length)} de {rows.length}</span>
              <button className="btn sec" disabled={paginaErroresActual === totalPaginasErrores} onClick={() => setPaginaErrores((p) => Math.min(totalPaginasErrores, p + 1))}>Siguiente</button>
            </nav>}
            <p className="note">Pulsa una fila para ver el detalle. Los datos se actualizan solos cada minuto.</p>
          </>
        )}
      </main>
      {verUsuarios && sesion && <Usuarios sesion={sesion} onClose={() => setVerUsuarios(false)} />}
      {cur && vista === 'copias' && (
        <Detalle c={cur} onClose={cerrar} acciones={copias} hist={hist} r={rep[claveEquipo(cur)] || 0} />
      )}
    </>
  );
}
