import { useState, useMemo, useEffect, useCallback, useReducer, useRef } from 'react';
import { iniciarSesion, api } from './api';
import { useCopias } from './hooks/useCopias';
import { TEST, norm, contadores, repeticiones, claveEquipo, coincideTipo } from './logic';
import Header from './components/Header';
import Kpis from './components/Kpis';
import Filtros from './components/Filtros';
import Tabla from './components/Tabla';
import Detalle from './components/Detalle';
import Clientes from './components/Clientes';
import Paginacion from './components/Paginacion';
import { usePagination } from './hooks/usePagination';

const fechaLocal = (fecha) => [fecha.getFullYear(), String(fecha.getMonth() + 1).padStart(2, '0'), String(fecha.getDate()).padStart(2, '0')].join('-');
const filtrosIniciales = () => {
  const hoy = new Date();
  const ayer = new Date(hoy);
  ayer.setDate(hoy.getDate() - 1);
  return { cli: '', tipo: '', desde: fechaLocal(ayer), hasta: fechaLocal(hoy), horas: 24 };
};

export default function App() {
  const [sesion, setSesion] = useState(null);
  const copias = useCopias();
  const [filtro, setFiltro] = useState('todos');
  const [f, setF] = useReducer((s, p) => ({ ...s, ...p }), undefined, filtrosIniciales);
  const [sel, setSel] = useState(null);
  const [selDialogo, setSelDialogo] = useState(null);
  const [vista, setVista] = useState('copias'); // 'copias' | 'clientes' | 'servidores'
  useEffect(() => { iniciarSesion().then(setSesion).catch(() => {}); }, []);

  // Casi tiempo real: cada 15 s pregunta (consulta ligera) si hay copias nuevas y, solo entonces, recarga la lista.
  const cargarRef = useRef(copias.cargar);
  cargarRef.current = copias.cargar;
  useEffect(() => {
    let ultima = null;
    const t = setInterval(async () => {
      if (document.hidden) return;
      try {
        const d = await api('novedades');
        const clave = d.n + ':' + d.m;
        if (ultima === null) { ultima = clave; return; }
        if (clave !== ultima) { ultima = clave; cargarRef.current(); }
      } catch { /* se reintenta en el siguiente ciclo */ }
    }, 15000);
    return () => clearInterval(t);
  }, []);

  const { data } = copias;
  const n = useMemo(() => contadores(data, f.horas), [data, f.horas]);
  const rep = useMemo(() => repeticiones(data), [data]);
  const rows = useMemo(() => {
    const q = norm(f.cli.trim());
    return data.filter((c) =>
      TEST[filtro](c, f.horas, rep) &&
      (!q || norm(c.cliente).includes(q)) &&
      coincideTipo(c, f.tipo) &&
      (!f.desde || c.fecha.slice(0, 10) >= f.desde) &&
      (!f.hasta || c.fecha.slice(0, 10) <= f.hasta));
  }, [data, filtro, f, rep]);
  const paginaCopias = usePagination(rows, 20, `${filtro}|${f.cli}|${f.tipo}|${f.desde}|${f.hasta}`);

  const abrir = useCallback((id) => setSel(id), []);
  const cerrar = useCallback(() => setSel(null), []);
  const cur = data.find((c) => c.id === sel);
  const copiaDialogo = data.find((c) => c.id === selDialogo);
  const hist = cur
    ? data.filter((x) => claveEquipo(x) === claveEquipo(cur) && x.id !== cur.id)
        .sort((a, b) => b.fecha.localeCompare(a.fecha))
    : [];

  return (
    <>
      <Header nombre={sesion ? sesion.nombre : ''} vista={vista} onVista={setVista} />
      <main>
        {(vista === 'clientes' || vista === 'servidores') && sesion ? (
          <Clientes key={vista} sesion={sesion} modo={vista} />
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
            <Tabla rows={paginaCopias.items} cargando={copias.cargando} onOpen={abrir} rep={rep} horas={f.horas} claveEquipo={claveEquipo} />
            <Paginacion total={rows.length} pagina={paginaCopias.page} paginas={paginaCopias.pageCount}
              porPagina={paginaCopias.pageSize} onCambiar={paginaCopias.setPage} etiqueta="copias" />
            <p className="note">Pulsa una fila para ver el detalle. Los datos se actualizan solos en cuanto llegan copias nuevas.</p>
          </>
        )}
      </main>
      {cur && vista === 'copias' && (
        <Detalle c={cur} onClose={cerrar} acciones={copias} hist={hist} r={rep[claveEquipo(cur)] || 0}
          onOpen={(id) => setSelDialogo(id)} manejarEscape={!copiaDialogo} />
      )}
      {copiaDialogo && vista === 'copias' && (
        <Detalle modo="dialog" c={copiaDialogo} onClose={() => setSelDialogo(null)} acciones={copias}
          hist={data.filter((x) => claveEquipo(x) === claveEquipo(copiaDialogo) && x.id !== copiaDialogo.id)
            .sort((a, b) => b.fecha.localeCompare(a.fecha))}
          r={rep[claveEquipo(copiaDialogo)] || 0} onOpen={(id) => setSelDialogo(id)} />
      )}
    </>
  );
}