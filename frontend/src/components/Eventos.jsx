import { useCallback, useEffect, useRef, useState } from 'react';
import { jsPDF } from 'jspdf';
import autoTable from 'jspdf-autotable';
import { api } from '../api';
import Paginacion from './Paginacion';

const POR_PAGINA = 50;
const niveles = [
  ['all', 'Todos los niveles'],
  ['Critical', 'Crítico'],
  ['Error', 'Error'],
  ['Warning', 'Advertencia'],
  ['Information', 'Informativo'],
];

function fechaHora(value) {
  if (!value) return '—';
  const parsed = new Date(String(value).replace(' ', 'T'));
  if (Number.isNaN(parsed.getTime())) return value;
  return new Intl.DateTimeFormat('es-ES', {
    dateStyle: 'medium',
    timeStyle: 'short',
  }).format(parsed);
}

function Nivel({ nivel }) {
  const estilo = nivel === 'Critical' ? 'critical'
    : nivel === 'Error' ? 'error'
      : nivel === 'Warning' ? 'warning' : 'other';
  const etiqueta = {
    Critical: 'Crítico',
    Error: 'Error',
    Warning: 'Advertencia',
    Information: 'Información',
  }[nivel] || nivel || '—';
  return <span className={`evento-nivel ${estilo}`}>{etiqueta}</span>;
}

export default function Eventos({ equipoId = null }) {
  const [buscar, setBuscar] = useState('');
  const [q, setQ] = useState('');
  const [nivel, setNivel] = useState('all');
  const [desde, setDesde] = useState('');
  const [hasta, setHasta] = useState('');
  const [exportando, setExportando] = useState(false);
  const [errorPdf, setErrorPdf] = useState('');
  const [pagina, setPagina] = useState(1);
  const [resultado, setResultado] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [error, setError] = useState('');
  const parametrosRef = useRef({ page: pagina, q, nivel, equipoId, desde, hasta });
  parametrosRef.current = { page: pagina, q, nivel, equipoId, desde, hasta };
  const enCursoRef = useRef(false);
  const pendienteRef = useRef(false);
  const activaRef = useRef(true);
  const cargarRef = useRef(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setPagina(1);
      setQ(buscar.trim());
    }, 250);
    return () => clearTimeout(timer);
  }, [buscar]);

  const cargar = useCallback(async () => {
    if (!activaRef.current) return;
    if (enCursoRef.current) {
      pendienteRef.current = true;
      return;
    }

    enCursoRef.current = true;
    const parametros = { ...parametrosRef.current };
    const clave = JSON.stringify(parametros);
    setCargando(true);
    try {
      const data = await api(
        `monitor_eventos&page=${parametros.page}&q=${encodeURIComponent(parametros.q)}&nivel=${encodeURIComponent(parametros.nivel)}${parametros.equipoId ? `&equipo_id=${parametros.equipoId}` : ''}${parametros.desde ? `&desde=${parametros.desde}` : ''}${parametros.hasta ? `&hasta=${parametros.hasta}` : ''}`,
      );
      if (!activaRef.current) return;
      if (clave === JSON.stringify(parametrosRef.current)) {
        setResultado({
          eventos: Array.isArray(data.eventos) ? data.eventos : [],
          total: Number(data.total) || 0,
          pagina: Number(data.pagina) || parametros.page,
          porPagina: Number(data.por_pagina) || POR_PAGINA,
        });
        setError('');
      } else {
        pendienteRef.current = true;
      }
    } catch (e) {
      if (activaRef.current && clave === JSON.stringify(parametrosRef.current)) {
        setError(e.message || 'No se pudieron cargar los eventos.');
      } else if (activaRef.current) {
        pendienteRef.current = true;
      }
    } finally {
      enCursoRef.current = false;
      if (activaRef.current) {
        setCargando(false);
        if (pendienteRef.current) {
          pendienteRef.current = false;
          cargarRef.current?.();
        }
      }
    }
  }, [equipoId]);
  cargarRef.current = cargar;

  useEffect(() => {
    activaRef.current = true;
    return () => {
      activaRef.current = false;
    };
  }, []);

  useEffect(() => {
    cargar();
  }, [cargar, pagina, q, nivel, desde, hasta]);

  useEffect(() => {
    const timer = setInterval(() => cargarRef.current?.(), 60_000);
    return () => clearInterval(timer);
  }, []);

  const eventos = resultado?.eventos || [];
  const total = resultado?.total || 0;
  const paginas = Math.max(1, Math.ceil(total / (resultado?.porPagina || POR_PAGINA)));
  const equipoNombre = eventos[0]?.equipo || `equipo #${equipoId}`;

  const imprimirRegistro = async () => {
    if (!equipoId || exportando) return;
    setExportando(true);
    setErrorPdf('');
    try {
      const tamano = resultado?.porPagina || POR_PAGINA;
      const query = (page) => `monitor_eventos&page=${page}&q=&nivel=all&equipo_id=${equipoId}&todo=1${desde ? `&desde=${desde}` : ''}${hasta ? `&hasta=${hasta}` : ''}`;
      const primera = await api(query(1));
      const todos = [...(primera.eventos || [])];
      const totalRegistros = Number(primera.total) || todos.length;
      for (let page = 2; page <= Math.ceil(totalRegistros / tamano); page += 1) {
        const siguiente = await api(query(page));
        todos.push(...(siguiente.eventos || []));
      }
      const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(18);
      doc.text('Registro de eventos de Windows', 14, 16);
      doc.setFont('helvetica', 'normal');
      doc.setFontSize(10);
      doc.text(`Equipo: ${todos[0]?.equipo || equipoNombre}`, 14, 23);
      doc.text(`Periodo: ${desde || 'sin inicio'} a ${hasta || 'sin fin'}  ·  ${todos.length} eventos  ·  Generado: ${new Date().toLocaleString('es-ES')}`, 14, 29);
      autoTable(doc, {
        startY: 34,
        head: [['Fecha y hora', 'Clasificación', 'Nivel original', 'Canal', 'Origen', 'Event ID', 'Mensaje']],
        body: todos.map((e) => [fechaHora(e.fecha), e.nivel, e.nivel_original || e.nivel, e.canal, e.origen, e.event_id, e.mensaje]),
        styles: { fontSize: 7, cellPadding: 2, overflow: 'linebreak' },
        headStyles: { fillColor: [28, 53, 81] },
        columnStyles: { 0: { cellWidth: 33 }, 1: { cellWidth: 25 }, 2: { cellWidth: 25 }, 3: { cellWidth: 22 }, 4: { cellWidth: 35 }, 5: { cellWidth: 15 }, 6: { cellWidth: 'auto' } },
        didDrawPage: () => {
          doc.setFontSize(8);
          doc.setTextColor(100);
          doc.text(`Página ${doc.internal.getNumberOfPages()}`, 282, 203, { align: 'right' });
        },
      });
      doc.save(`eventos-equipo-${equipoId}.pdf`);
    } catch (e) {
      setErrorPdf(e.message || 'No se pudo generar el PDF del registro.');
    } finally {
      setExportando(false);
    }
  };

  return (
    <section className="eventos-page" aria-labelledby="eventos-title">
      <div className="eventos-heading">
        <div>
          <span className="eyebrow">Monitor de equipos</span>
          <h2 id="eventos-title">{equipoId ? `Eventos de ${equipoNombre}` : 'Eventos de Windows'}</h2>
          <p>{equipoId
            ? 'En Estándar se muestran errores y críticos de System y Application de los últimos 30 días; Auditoría Completa incluye los niveles y canales disponibles.'
            : 'Estándar recoge errores y críticos de System y Application de los últimos 30 días. Auditoría Completa incluye todos los niveles y canales disponibles.'}</p>
        </div>
        <div className="eventos-actualizacion" aria-live="polite">
          <span className={`eventos-indicador${cargando ? ' actualizando' : ''}`} aria-hidden="true" />
          {cargando ? 'Actualizando…' : 'Actualizado automáticamente cada minuto'}
        </div>
      </div>

      <div className="eventos-controles">
        <label className="eventos-busqueda" htmlFor="eventos-buscar">
          <span>Buscar</span>
          <input
            id="eventos-buscar"
            type="search"
            value={buscar}
            onChange={(event) => setBuscar(event.target.value)}
            placeholder="Cliente, equipo, origen o mensaje"
          />
        </label>
        <label className="eventos-fecha" htmlFor="eventos-desde"><span>Desde</span><input id="eventos-desde" type="date" value={desde} max={hasta || undefined} onChange={(event) => { setDesde(event.target.value); setPagina(1); }} /></label>
        <label className="eventos-fecha" htmlFor="eventos-hasta"><span>Hasta</span><input id="eventos-hasta" type="date" value={hasta} min={desde || undefined} onChange={(event) => { setHasta(event.target.value); setPagina(1); }} /></label>
        {equipoId && <button className="btn sec eventos-pdf" onClick={imprimirRegistro} disabled={exportando}>{exportando ? 'Preparando PDF…' : 'Imprimir registro PDF'}</button>}
        <label className="eventos-nivel" htmlFor="eventos-nivel">
          <span>Nivel</span>
          <select
            id="eventos-nivel"
            value={nivel}
            onChange={(event) => {
              setNivel(event.target.value);
              setPagina(1);
            }}
          >
            {niveles.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
          </select>
        </label>
        <p className="eventos-recuento" aria-live="polite">
          {resultado === null && cargando ? 'Cargando resultados…' : `${total.toLocaleString('es-ES')} evento${total === 1 ? '' : 's'}`}
        </p>
      </div>

      {errorPdf && <div className="errbox eventos-error" role="alert">{errorPdf}</div>}

      {error && (
        <div className="errbox eventos-error" role="alert">
          No se pudieron cargar los eventos: {error}{' '}
          <button className="btn sec" onClick={cargar}>Reintentar</button>
        </div>
      )}

      {resultado === null && cargando ? (
        <div className="eventos-estado" role="status">Cargando eventos…</div>
      ) : eventos.length === 0 ? (
        <div className="eventos-estado">
          <strong>{error ? 'No hay resultados disponibles' : 'No se encontraron eventos'}</strong>
          <span>{error ? 'Comprueba la conexión e inténtalo de nuevo.' : 'Prueba a cambiar los filtros o la búsqueda.'}</span>
        </div>
      ) : (
        <div className="wrap eventos-tabla-wrap" role="region"
          aria-label={equipoId ? `Eventos de Windows de ${equipoNombre}` : 'Eventos de Windows'} tabIndex="0">
          <table className="eventos-table">
            <thead>
              <tr>
                <th scope="col">Fecha y hora</th>
                <th scope="col">Cliente</th>
                <th scope="col">Equipo</th>
                <th scope="col">Clasificación</th>
                <th scope="col">Canal</th>
                <th scope="col">Origen</th>
                <th scope="col">Event ID</th>
                <th scope="col">Mensaje</th>
              </tr>
            </thead>
            <tbody>
              {eventos.map((evento) => (
                <tr key={evento.id}>
                  <td className="evento-fecha">{fechaHora(evento.fecha)}</td>
                  <td className="evento-cliente">{evento.cliente || '—'}</td>
                  <td className="evento-equipo">{evento.equipo || '—'}</td>
                  <td><Nivel nivel={evento.nivel} />{evento.nivel_original && evento.nivel_original !== evento.nivel && <small className="evento-nivel-original">Windows: {evento.nivel_original}</small>}</td>
                  <td className="evento-canal">{evento.canal || '—'}</td>
                  <td className="evento-origen">{evento.origen || '—'}</td>
                  <td className="evento-id">{evento.event_id || '—'}</td>
                  <td className="evento-mensaje">{evento.mensaje || '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Paginacion
        total={total}
        pagina={resultado?.pagina || pagina}
        paginas={paginas}
        porPagina={resultado?.porPagina || POR_PAGINA}
        onCambiar={setPagina}
        etiqueta="eventos"
      />
    </section>
  );
}
