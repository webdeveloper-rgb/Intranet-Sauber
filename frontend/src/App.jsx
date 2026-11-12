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
import Usuarios from './components/Usuarios';
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
  const [cuentaAbierta, setCuentaAbierta] = useState(false);
  const [actualizacionEquipos, setActualizacionEquipos] = useState(0);
  const [vista, setVista] = useState('copias'); // 'copias' | 'clientes' | 'servidores'
  useEffect(() => { iniciarSesion().then(setSesion).catch(() => {}); }, []);

  // Detecta cambios de copias y equipos mientras la sesión del panel está abierta.
  const cargarRef = useRef(copias.cargar);
  cargarRef.current = copias.cargar;
  const falloRef = useRef(copias.fallo);
  falloRef.current = copias.fallo;
  const vistaActual = useRef(vista);
  vistaActual.current = vista;
  useEffect(() => {
    if (!sesion) return undefined;
    let ultima = null;
    let comprobando = false;
    let activa = true;
    const comprobar = async () => {
      if (!activa || document.hidden || comprobando) return;
      comprobando = true;
      try {
        const d = await api('novedades');
        const clave = JSON.stringify([d.m, d.staging, d.ingesta, d.ausentes]);
        if (ultima === null) {
          ultima = clave;
          return;
        }
        const cambio = clave !== ultima;
        if (cambio) {
          ultima = clave;
          setActualizacionEquipos((actual) => actual + 1);
        }
        if (vistaActual.current === 'copias' && (cambio || falloRef.current)) cargarRef.current();
      } catch { /* se reintenta en el siguiente ciclo */ }
      finally { comprobando = false; }
    };
    comprobar();
    const t = setInterval(comprobar, 5000);
    return () => {
      activa = false;
      clearInterval(t);
    };
  }, [sesion]);

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
      <Header nombre={sesion ? sesion.nombre : ''} vista={vista} onVista={setVista}
        esAdmin={sesion?.rol === 'admin'} onCuenta={() => setCuentaAbierta(true)} />
      <main>
        {(vista === 'clientes' || vista === 'servidores') && sesion ? (
          <Clientes key={vista} sesion={sesion} modo={vista} refreshSignal={actualizacionEquipos} />
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
            {copias.hayMasHistorial && (
              <div className="historial-cargar">
                <button className="btn sec" disabled={copias.cargandoHistorial} onClick={async () => {
                  const resultado = await copias.cargarMasHistorial();
                  if (resultado?.desde) setF({ desde: resultado.desde });
                }}>
                  {copias.cargandoHistorial ? 'Cargando historial…' : 'Cargar 500 copias anteriores'}
                </button>
                <span className="note">El historial anterior se carga solo cuando lo solicitas.</span>
              </div>
            )}
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
      {sesion && cuentaAbierta && <Usuarios sesion={sesion} onClose={() => setCuentaAbierta(false)} />}
    </>
  );
}
