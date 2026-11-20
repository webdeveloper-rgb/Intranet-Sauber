import { useCallback, useEffect, useRef, useState } from 'react';
import { api } from '../../../shared/api/client';
import Paginacion from '../../../shared/ui/Paginacion';
import BarraFiltros from '../../../shared/ui/BarraFiltros';
import PageHeading from '../../../shared/ui/PageHeading';

const POR_PAGINA = 10;
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

export default function Eventos({ equipoId = null, integrado = false }) {
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
  const filtrosActivos = [
    ...(buscar.trim() ? [{ clave: 'q', etiqueta: `Búsqueda: ${buscar.trim()}` }] : []),
    ...(nivel !== 'all' ? [{ clave: 'nivel', etiqueta: `Nivel: ${niveles.find(([key]) => key === nivel)?.[1] || nivel}` }] : []),
    ...(desde ? [{ clave: 'desde', etiqueta: `Desde: ${desde}` }] : []),
    ...(hasta ? [{ clave: 'hasta', etiqueta: `Hasta: ${hasta}` }] : []),
  ];
  const quitarFiltro = (clave) => {
    if (clave === 'q') { setBuscar(''); setQ(''); }
    else if (clave === 'nivel') setNivel('all');
    else if (clave === 'desde') setDesde('');
    else if (clave === 'hasta') setHasta('');
    setPagina(1);
  };
  const limpiarFiltros = () => {
    setBuscar('');
    setQ('');
    setNivel('all');
    setDesde('');
    setHasta('');
    setPagina(1);
  };

  const imprimirRegistro = async () => {
    if (!equipoId || exportando) return;
    setExportando(true);
    setErrorPdf('');
    try {
      const [{ jsPDF }, { default: autoTable }] = await Promise.all([
        import('jspdf'),
        import('jspdf-autotable'),
      ]);
      const tamano = resultado?.porPagina || POR_PAGINA;
      const query = (page) => `monitor_eventos&page=${page}&q=&nivel=all&equipo_id=${equipoId}${desde ? `&desde=${desde}` : ''}${hasta ? `&hasta=${hasta}` : ''}`;
      const primera = await api(query(1));
      const todos = [...(primera.eventos || [])];
      const totalRegistros = Number(primera.total) || todos.length;
      for (let page = 2; page <= Math.ceil(totalRegistros / tamano); page += 1) {
        const siguiente = await api(query(page));
        todos.push(...(siguiente.eventos || []));
      }
      const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
      const ancho = doc.internal.pageSize.getWidth();
      const clasificaciones = [
        ['Critical', 'Críticos', [150, 42, 52]],
        ['Error', 'Errores', [184, 76, 62]],
        ['Warning', 'Advertencias', [224, 157, 51]],
        ['Information', 'Informativos', [68, 126, 168]],
      ];
      const conteoNivel = new Map(clasificaciones.map(([clave]) => [clave, 0]));
      const tipos = new Map();
      todos.forEach((evento) => {
        const nivelEvento = conteoNivel.has(evento.nivel) ? evento.nivel : 'Error';
        conteoNivel.set(nivelEvento, conteoNivel.get(nivelEvento) + 1);
        const origen = evento.origen || 'Sin origen';
        const canal = evento.canal || 'Sin canal';
        const clave = JSON.stringify([nivelEvento, canal, origen, evento.event_id]);
        const tipo = tipos.get(clave) || { nivel: nivelEvento, canal, origen, eventId: evento.event_id, cantidad: 0 };
        tipo.cantidad += 1;
        tipos.set(clave, tipo);
      });
      const ordenarTipos = [...tipos.values()].sort((a, b) => b.cantidad - a.cantidad || String(a.origen).localeCompare(String(b.origen)));

      // Portada y resumen ejecutivo.
      doc.setFillColor(28, 53, 81);
      doc.rect(9, 9, ancho - 18, 36, 'F');
      doc.setFont('times', 'bold');
      doc.setFontSize(23);
      doc.setTextColor(255, 255, 255);
      doc.text('Informe de eventos de Windows', 16, 25);
      doc.setFont('times', 'normal');
      doc.setFontSize(12);
      doc.setTextColor(225, 234, 242);
      doc.text(`Equipo: ${todos[0]?.equipo || equipoNombre}`, 16, 37);
      doc.setTextColor(40, 51, 64);
      doc.setFontSize(11);
      doc.text(`Periodo: ${desde || 'todo el historial'}${hasta ? ` a ${hasta}` : ''}`, 9, 53);
      doc.text(`Generado: ${new Date().toLocaleString('es-ES')}`, ancho - 9, 53, { align: 'right' });

      const tarjetaY = 60;
      const tarjetaGap = 5;
      const tarjetaAncho = (ancho - 18 - tarjetaGap * 3) / 4;
      clasificaciones.forEach(([clave, titulo, color], indice) => {
        const x = 9 + indice * (tarjetaAncho + tarjetaGap);
        doc.setFillColor(245, 247, 250);
        doc.rect(x, tarjetaY, tarjetaAncho, 24, 'F');
        doc.setFillColor(...color);
        doc.rect(x, tarjetaY, 2.5, 24, 'F');
        doc.setFont('times', 'normal');
        doc.setFontSize(10);
        doc.setTextColor(86, 99, 112);
        doc.text(titulo, x + 8, tarjetaY + 9);
        doc.setFont('times', 'bold');
        doc.setFontSize(18);
        doc.setTextColor(28, 42, 57);
        doc.text(String(conteoNivel.get(clave)), x + 8, tarjetaY + 20);
      });

      doc.setFont('times', 'bold');
      doc.setFontSize(16);
      doc.setTextColor(28, 53, 81);
      doc.text('Conteo por tipo de evento', 9, 94);
      doc.setFont('times', 'normal');
      doc.setFontSize(10);
      doc.setTextColor(90, 100, 112);
      doc.text(`${todos.length.toLocaleString('es-ES')} registros · agrupados por clasificación, canal, origen y Event ID`, 9, 100);
      autoTable(doc, {
        startY: 104,
        head: [['Clasificación', 'Canal', 'Origen', 'Event ID', 'Cantidad']],
        body: ordenarTipos.map((tipo) => [tipo.nivel, tipo.canal, tipo.origen, tipo.eventId, tipo.cantidad]),
        styles: { font: 'times', fontSize: 10, cellPadding: 3, overflow: 'linebreak' },
        headStyles: { fillColor: [28, 53, 81], textColor: 255 },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        columnStyles: { 0: { cellWidth: 30 }, 1: { cellWidth: 32 }, 2: { cellWidth: 'auto' }, 3: { cellWidth: 25 }, 4: { cellWidth: 25, halign: 'right' } },
        margin: { left: 9, right: 9, bottom: 14 },
      });

      doc.addPage();
      doc.setFont('times', 'bold');
      doc.setFontSize(18);
      doc.setTextColor(28, 53, 81);
      doc.text('Registro de eventos', 9, 18);
      doc.setFont('times', 'normal');
      doc.setFontSize(10);
      doc.setTextColor(90, 100, 112);
      doc.text('Vista compacta para lectura rápida; los mensajes se limitan a 220 caracteres.', 9, 25);
      autoTable(doc, {
        startY: 30,
        head: [['Fecha y hora', 'Clasificación', 'Nivel Windows', 'Canal', 'Origen', 'ID', 'Resumen del mensaje']],
        body: todos.map((e) => [fechaHora(e.fecha), e.nivel, e.nivel_original || e.nivel, e.canal, e.origen, e.event_id, String(e.mensaje || 'Sin mensaje').replace(/\s+/g, ' ').slice(0, 220) + (String(e.mensaje || '').length > 220 ? '…' : '')]),
        styles: { font: 'times', fontSize: 9.5, cellPadding: 2.4, overflow: 'linebreak', valign: 'top' },
        headStyles: { fillColor: [28, 53, 81], textColor: 255 },
        alternateRowStyles: { fillColor: [245, 247, 250] },
        columnStyles: { 0: { cellWidth: 29 }, 1: { cellWidth: 25 }, 2: { cellWidth: 25 }, 3: { cellWidth: 25 }, 4: { cellWidth: 39 }, 5: { cellWidth: 14 }, 6: { cellWidth: 'auto' } },
        margin: { left: 9, right: 9, bottom: 14 },
      });

      const paginasPdf = doc.internal.getNumberOfPages();
      for (let paginaPdf = 1; paginaPdf <= paginasPdf; paginaPdf += 1) {
        doc.setPage(paginaPdf);
        doc.setDrawColor(220, 225, 231);
        doc.line(9, 201, ancho - 9, 201);
        doc.setFont('times', 'normal');
        doc.setFontSize(9);
        doc.setTextColor(110, 120, 130);
        doc.text(`Registro de eventos · ${todos.length} eventos · mensajes resumidos a 220 caracteres`, 9, 206);
        doc.text(`Página ${paginaPdf} de ${paginasPdf}`, ancho - 9, 206, { align: 'right' });
      }
      doc.save(`eventos-equipo-${equipoId}.pdf`);
    } catch (e) {
      setErrorPdf(e.message || 'No se pudo generar el PDF del registro.');
    } finally {
      setExportando(false);
    }
  };

  return (
    <section className="eventos-page" aria-labelledby="eventos-title">
      <PageHeading id="eventos-title" className="eventos-heading" eyebrow={integrado ? undefined : 'Monitor de equipos'} level={integrado ? 2 : 1}
        title={equipoId ? `Eventos de ${equipoNombre}` : 'Eventos de Windows'}
        description={equipoId
          ? 'Historial del equipo, con filtros por fecha, nivel y origen.'
          : 'Historial de eventos, filtrable por equipo, nivel y origen.'}
        actions={<>
          <div className="eventos-actualizacion" aria-live="polite">
            <span className={`eventos-indicador${cargando ? ' actualizando' : ''}`} aria-hidden="true" />
            {cargando ? 'Actualizando…' : 'Auto cada minuto'}
          </div>
        </>} />

      <BarraFiltros title="Filtrar eventos"
        search={{ id: 'eventos-buscar', value: buscar, placeholder: 'Cliente, equipo, origen o mensaje',
          onChange: (event) => setBuscar(event.target.value) }}
        fields={[
          { id: 'eventos-desde', label: 'Desde', type: 'date', value: desde, max: hasta || undefined,
            onChange: (event) => { setDesde(event.target.value); setPagina(1); } },
          { id: 'eventos-hasta', label: 'Hasta', type: 'date', value: hasta, min: desde || undefined,
            onChange: (event) => { setHasta(event.target.value); setPagina(1); } },
          { id: 'eventos-nivel', label: 'Nivel', type: 'select', value: nivel,
            onChange: (event) => { setNivel(event.target.value); setPagina(1); }, options: niveles },
        ]}
        actions={equipoId && <button className="btn sec eventos-pdf" type="button" onClick={imprimirRegistro} disabled={exportando}>
          {exportando ? 'Preparando PDF…' : 'Imprimir registro PDF'}
        </button>}
        count={resultado === null && cargando ? 'Cargando resultados…'
          : `${total.toLocaleString('es-ES')} evento${total === 1 ? '' : 's'}`}
        activeFilters={filtrosActivos} onRemoveFilter={quitarFiltro} onClear={limpiarFiltros} />

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
