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

  // Consulta cambios solo mientras se muestra Copias; la lista se actualiza sin recargar la página.
  const cargarRef = useRef(copias.cargar);
  cargarRef.current = copias.cargar;
  const falloRef = useRef(copias.fallo);
  falloRef.current = copias.fallo;
  const vistaAnterior = useRef(vista);
  useEffect(() => {
    if (vista !== 'copias') {
      vistaAnterior.current = vista;
      return;
    }
    if (vistaAnterior.current !== 'copias') cargarRef.current();
    vistaAnterior.current = 'copias';

    let ultima = null;
    let comprobando = false;
    const comprobar = async () => {
      if (document.hidden || comprobando) return;
      comprobando = true;
      try {
        const d = await api('novedades');
        const clave = d.n + ':' + d.m + ':' + d.ausentes;
        if (ultima === null) {
          ultima = clave;
          cargarRef.current();
          return;
        }
        if (clave !== ultima || falloRef.current) {
          ultima = clave;
          cargarRef.current();
        }
      } catch { /* se reintenta en el siguiente ciclo */ }
      finally { comprobando = false; }
    };
    const t = setInterval(comprobar, 5000);
    return () => clearInterval(t);
  }, [vista]);

  const { data } = copias;
  const n = useMemo(() => contadores(data, f.horas), [data, f.horas]);
  const rep = useMemo(() => repeticiones(data), [data]);
  const rows = useMemo(() => {
    const q = norm(f.cli.trim());
    return data.filter((c) => {
      const sinCopia = c.codigo === 'SIN_COPIA';
      if (sinCopia !== (filtro === 'sin')) return false;
      return (
        TEST[filtro](c, f.horas, rep) &&
        (!q || norm(c.cliente).includes(q)) &&
        coincideTipo(c, f.tipo) &&
        (sinCopia || !f.desde || c.fecha.slice(0, 10) >= f.desde) &&
        (sinCopia || !f.hasta || c.fecha.slice(0, 10) <= f.hasta)
      );
    });
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
            <Kpis n={n} total={data.length - n.sc} />
            <Filtros filtro={filtro} setFiltro={setFiltro} n={n} f={f} setF={setF} resultados={rows.length} />
            {filtro === 'sin' && (
              <section className="sin-copia-resumen">
                <div>
                  <h2>Equipos sin copia recibida</h2>
                  <p>Se muestran equipos asignados y vigilados que no enviaron una copia en su último día programado.</p>
                </div>
                <strong>{rows.length}<span> equipo{rows.length === 1 ? '' : 's'}</span></strong>
              </section>
            )}
            <Tabla sinCopia={filtro === 'sin'} rows={paginaCopias.items} cargando={copias.cargando}
              onOpen={abrir} rep={rep} horas={f.horas} claveEquipo={claveEquipo} />
            <Paginacion total={rows.length} pagina={paginaCopias.page} paginas={paginaCopias.pageCount}
              porPagina={paginaCopias.pageSize} onCambiar={paginaCopias.setPage} etiqueta="copias" />
            <p className="note">Pulsa una fila para ver el detalle. La tabla comprueba cambios cada 5 segundos sin recargar la página.</p>
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