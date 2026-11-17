/**
 * Composicion de vistas, estado global, rutas del navegador y refresco de novedades.
 */
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
import Eventos from './components/Eventos';
import Notificaciones from './components/Notificaciones';
import Paginacion from './components/Paginacion';
import { usePagination } from './hooks/usePagination';
import { leerRuta, rutaHref } from './routes';

const fechaLocal = (fecha) => [fecha.getFullYear(), String(fecha.getMonth() + 1).padStart(2, '0'), String(fecha.getDate()).padStart(2, '0')].join('-');
const filtrosIniciales = () => {
  const hoy = new Date();
  const ayer = new Date(hoy);
  ayer.setDate(hoy.getDate() - 1);
  return { cli: '', tipo: '', software: '', desde: fechaLocal(ayer), hasta: fechaLocal(hoy), orden: 'fecha-desc', horas: 24 };
};

export default function App() {
  const [sesion, setSesion] = useState(null);
  const [ruta, setRuta] = useState(leerRuta);
  const [filtro, setFiltro] = useState('todos');
  const [f, setF] = useReducer((s, p) => ({ ...s, ...p }), undefined, filtrosIniciales);
  const copias = useCopias(f.desde, f.hasta);
  const [copiaSeleccionada, setCopiaSeleccionada] = useState(null);
  const [cuentaAbierta, setCuentaAbierta] = useState(false);
  const [notificacionesAbiertas, setNotificacionesAbiertas] = useState(false);
  const [actualizacionEquipos, setActualizacionEquipos] = useState(0);
  const vista = ruta.vista;
  const historialInterno = useRef([]);
  const hrefActual = useRef(`${window.location.pathname}${window.location.search}`);
  useEffect(() => { iniciarSesion().then(setSesion).catch(() => {}); }, []);

  const navegar = useCallback((path) => {
    const href = rutaHref(path);
    const actual = `${window.location.pathname}${window.location.search}`;
    if (actual !== href) {
      historialInterno.current.push(actual);
      window.history.pushState({ panelSauber: true }, '', href);
      hrefActual.current = href;
    }
    setRuta(leerRuta());
    if (!/^\/copias\/\d+$/.test(path)) setCopiaSeleccionada(null);
    window.scrollTo(0, 0);
  }, []);

  const volver = useCallback(() => {
    if (historialInterno.current.length > 0) {
      window.history.back();
      return;
    }
    const actual = leerRuta();
    let destino = '/copias';
    if (actual.vista === 'clientes') {
      if (actual.equipoId) destino = actual.clienteId
        ? `/clientes/${encodeURIComponent(String(actual.clienteId))}`
        : '/clientes';
      else if (actual.clienteId) destino = '/clientes';
    } else if (actual.vista === 'eventos' && actual.equipoId) {
      destino = '/clientes';
    }
    navegar(destino);
  }, [navegar]);

  useEffect(() => {
    const sincronizarRuta = () => {
      const actual = `${window.location.pathname}${window.location.search}`;
      const anterior = hrefActual.current;
      const indiceAnterior = historialInterno.current.lastIndexOf(actual);
      if (indiceAnterior >= 0) historialInterno.current.length = indiceAnterior;
      else if (actual !== anterior) historialInterno.current.push(anterior);
      hrefActual.current = actual;
      setRuta(leerRuta());
      if (!leerRuta().copiaId) setCopiaSeleccionada(null);
    };
    window.addEventListener('popstate', sincronizarRuta);
    const canonica = rutaHref(ruta.path);
    if (`${window.location.pathname}${window.location.search}` !== canonica) window.history.replaceState({}, '', canonica);
    return () => window.removeEventListener('popstate', sincronizarRuta);
  }, []);

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
          // Asegura que la lista queda sincronizada con el marcador inicial,
          // aunque entrara una copia mientras terminaba la carga de sesión.
          if (vistaActual.current === 'copias') cargarRef.current();
          else setActualizacionEquipos((actual) => actual + 1);
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
    const ordenados = data.filter((c) => {
      const sinCopia = c.codigo === 'SIN_COPIA';
      if (sinCopia !== (filtro === 'sin')) return false;
      return (
        TEST[filtro](c, f.horas, rep) &&
        (!q || norm([c.cliente, c.equipo, c.software, c.programa, c.destino, c.desc_error, c.codigo].filter(Boolean).join(' ')).includes(q)) &&
        coincideTipo(c, f.tipo) &&
        (!f.software || c.software === f.software) &&
        (!f.desde || c.fecha.slice(0, 10) >= f.desde) &&
        (!f.hasta || c.fecha.slice(0, 10) <= f.hasta)
      );
    });
    const compararTexto = (a, b, key, direccion = 1) => String(a[key] || '').localeCompare(String(b[key] || ''), 'es', { sensitivity: 'base' }) * direccion;
    return ordenados.sort((a, b) => {
      if (f.orden === 'fecha-asc') return String(a.fecha).localeCompare(String(b.fecha)) || Number(a.id) - Number(b.id);
      if (f.orden === 'cliente-az') return compararTexto(a, b, 'cliente') || compararTexto(a, b, 'equipo');
      if (f.orden === 'cliente-za') return compararTexto(a, b, 'cliente', -1) || compararTexto(a, b, 'equipo');
      if (f.orden === 'equipo-az') return compararTexto(a, b, 'equipo') || compararTexto(a, b, 'cliente');
      if (f.orden === 'equipo-za') return compararTexto(a, b, 'equipo', -1) || compararTexto(a, b, 'cliente');
      return String(b.fecha).localeCompare(String(a.fecha)) || Number(b.id) - Number(a.id);
    });
  }, [data, filtro, f, rep]);
  const softwaresCopia = useMemo(() => [...new Set(data.map((c) => c.software).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es')), [data]);
  const paginaCopias = usePagination(rows, 20, `${filtro}|${f.cli}|${f.tipo}|${f.software}|${f.desde}|${f.hasta}|${f.orden}`);

  const abrir = useCallback((id) => {
    setCopiaSeleccionada(Number(id));
    navegar(`/copias/${id}`);
  }, [navegar]);
  const cerrar = useCallback(() => {
    setCopiaSeleccionada(null);
    navegar('/copias');
  }, [navegar]);
  const abrirNotificacion = useCallback((notificacion) => {
    setNotificacionesAbiertas(false);
    if (notificacion.tipo === 'crash' || notificacion.tipo === 'windows') {
      navegar(`/eventos?equipo_id=${notificacion.equipo_id}`);
    } else if (notificacion.tipo === 'copias_repetidas' && notificacion.copia_id) {
      abrir(notificacion.copia_id);
    } else {
      const cliente = notificacion.cliente_id || 'sin-asignar';
      navegar(`/clientes/${encodeURIComponent(cliente)}/equipos/${notificacion.equipo_id}`);
    }
  }, [abrir, navegar]);
  const idCopiaAbierta = ruta.copiaId ?? copiaSeleccionada;
  const cur = data.find((c) => Number(c.id) === Number(idCopiaAbierta));
  return (
    <>
      <Header nombre={sesion ? sesion.nombre : ''} vista={vista} onNavegar={navegar} onVolver={volver}
        esAdmin={sesion?.rol === 'admin'}
        onCuenta={() => { setNotificacionesAbiertas(false); setCuentaAbierta(true); }}
        onNotificaciones={() => { setCuentaAbierta(false); setNotificacionesAbiertas(true); }} />
      <main>
        {vista === 'eventos' ? (
          sesion ? <Eventos key={`${vista}-${ruta.equipoId || 'todos'}`} equipoId={ruta.equipoId} /> : <div className="eventos-estado" role="status">Comprobando sesión…</div>
        ) : vista === 'clientes' && sesion ? (
          <Clientes key={vista} sesion={sesion} refreshSignal={actualizacionEquipos}
            clienteId={ruta.clienteId} equipoId={ruta.equipoId} onNavigate={navegar} acciones={copias} />
        ) : (
          <>
            {copias.fallo && (
              <div className="errbox">
                No se pudieron cargar los datos: {copias.fallo}{' '}
                <button className="btn sec" onClick={copias.cargar}>Reintentar</button>
              </div>
            )}
            <Kpis n={n} total={data.length - n.sc} />
            <Filtros filtro={filtro} setFiltro={setFiltro} n={n} f={f} setF={setF} resultados={rows.length} softwares={softwaresCopia} />
            {copias.truncado && <div className="estado-alertas" role="status">Este intervalo contiene más de 3.000 copias; se muestran las 3.000 más recientes. Reduce el rango de fechas para ver el resto.</div>}
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
          </>
        )}
      </main>
      {cur && vista === 'copias' && (
        <Detalle c={cur} onClose={cerrar} acciones={copias} />
      )}
      {sesion && cuentaAbierta && <Usuarios sesion={sesion} onClose={() => setCuentaAbierta(false)} />}
      {sesion && notificacionesAbiertas && <Notificaciones onClose={() => setNotificacionesAbiertas(false)}
        onAbrir={abrirNotificacion} />}
    </>
  );
}
