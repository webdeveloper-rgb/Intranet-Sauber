import { useCallback, useEffect, useState } from 'react';
import { api } from '../../../shared/api/client';
import Paginacion from '../../../shared/ui/Paginacion';
import Tag from '../components/Tag';
import PageHeading from '../../../shared/ui/PageHeading';
import BarraFiltros from '../../../shared/ui/BarraFiltros';
import { etiquetaEstado } from '../../../shared/lib/logic';

const fechaLocal = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const fechaInicial = () => {
  const date = new Date();
  date.setDate(date.getDate() - 30);
  return fechaLocal(date);
};
const fuenteEtiqueta = (fuente) => fuente === 'legacy' ? 'Dato histórico (clasificación anterior)' : fuente || 'Sin registrar';

export default function InformeCopias({ onNavigate }) {
  const [desde, setDesde] = useState(fechaInicial);
  const [hasta, setHasta] = useState(() => fechaLocal(new Date()));
  const [estado, setEstado] = useState('all');
  const [busqueda, setBusqueda] = useState('');
  const [consulta, setConsulta] = useState({ desde: fechaInicial(), hasta: fechaLocal(new Date()), estado: 'all', q: '' });
  const [pagina, setPagina] = useState(1);
  const [resultado, setResultado] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [exportando, setExportando] = useState(false);
  const [error, setError] = useState('');

  const cargar = useCallback(async (page = 1) => {
    setCargando(true);
    setError('');
    try {
      const data = await api(`informe_copias&${new URLSearchParams({ desde: consulta.desde, hasta: consulta.hasta, estado: consulta.estado, q: consulta.q, page: String(page) })}`);
      setResultado(data);
      setPagina(page);
    } catch (e) {
      setError(e.message || 'No se pudo generar el informe.');
    } finally {
      setCargando(false);
    }
  }, [consulta]);

  useEffect(() => { cargar(1); }, [cargar]);

  const aplicar = (event) => {
    event.preventDefault();
    setConsulta({ desde, hasta, estado, q: busqueda.trim() });
    setPagina(1);
  };

  const generarPdf = async (imprimir = false) => {
    if (exportando || !resultado?.total) return;
    setExportando(true);
    setError('');
    try {
      const rows = [];
      const pages = Math.ceil(resultado.total / (resultado.por_pagina || 100));
      for (let page = 1; page <= pages; page += 1) {
        const data = await api(`informe_copias&${new URLSearchParams({ desde: consulta.desde, hasta: consulta.hasta, estado: consulta.estado, q: consulta.q, page: String(page) })}`);
        rows.push(...(data.copias || []));
      }
      const [{ jsPDF }, { default: autoTable }] = await Promise.all([import('jspdf'), import('jspdf-autotable')]);
      const doc = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
      const ancho = doc.internal.pageSize.getWidth();
      const alto = doc.internal.pageSize.getHeight();
      const filtros = [consulta.desde && `Desde ${consulta.desde}`, consulta.hasta && `Hasta ${consulta.hasta}`,
        consulta.estado !== 'all' && `Estado ${consulta.estado}`, consulta.q && `Búsqueda: ${consulta.q}`].filter(Boolean).join(' · ') || 'Sin filtros';
      doc.setProperties({ title: 'Informe de copias', subject: filtros, creator: 'Intranet-Sauber' });
      doc.setFillColor(27, 48, 70); doc.rect(0, 0, ancho, 39, 'F');
      doc.setFont('helvetica', 'bold'); doc.setFontSize(20); doc.setTextColor(255, 255, 255); doc.text('Informe de copias', 14, 19);
      doc.setFont('helvetica', 'normal'); doc.setFontSize(10); doc.setTextColor(220, 230, 239); doc.text(filtros, 14, 29);
      doc.setTextColor(65, 79, 94); doc.setFontSize(10); doc.text(`${rows.length.toLocaleString('es-ES')} registros`, 14, 48);
      doc.text(`Generado: ${new Date().toLocaleString('es-ES')}`, ancho - 14, 48, { align: 'right' });
      autoTable(doc, { startY: 54, head: [['Fecha', 'Cliente', 'Equipo', 'Software', 'Estado', 'Detalle', 'Código', 'Destino']],
        body: rows.map((item) => [item.fecha, item.cliente, item.equipo, item.software || '—', etiquetaEstado(item),
          item.desc_error || item.clasificacion_evidencia || '—', item.codigo || '—', item.destino || '—']),
        styles: { font: 'helvetica', fontSize: 8.5, cellPadding: 2.3, overflow: 'linebreak', valign: 'top' },
        headStyles: { fillColor: [34, 67, 96], textColor: 255 }, alternateRowStyles: { fillColor: [245, 248, 251] },
        columnStyles: { 0: { cellWidth: 26 }, 1: { cellWidth: 32 }, 2: { cellWidth: 32 }, 3: { cellWidth: 23 }, 4: { cellWidth: 25 }, 5: { cellWidth: 'auto' }, 6: { cellWidth: 25 }, 7: { cellWidth: 28 } },
        margin: { left: 10, right: 10, bottom: 15 }, });
      const paginasPdf = doc.internal.getNumberOfPages();
      for (let paginaPdf = 1; paginaPdf <= paginasPdf; paginaPdf += 1) { doc.setPage(paginaPdf); doc.setFontSize(8); doc.setTextColor(105, 117, 129); doc.text('Intranet-Sauber · Informe de copias', 10, alto - 8); doc.text(`Página ${paginaPdf} de ${paginasPdf}`, ancho - 10, alto - 8, { align: 'right' }); }
      const nombre = `informe-copias-${consulta.desde || 'historial'}-${consulta.hasta || 'actual'}.pdf`;
      if (imprimir) { doc.autoPrint(); window.open(doc.output('bloburl'), '_blank', 'noopener'); } else doc.save(nombre);
    } catch (e) {
      setError(e.message || 'No se pudo generar el PDF del informe.');
    } finally {
      setExportando(false);
    }
  };

  const total = Number(resultado?.total || 0);
  const porPagina = Number(resultado?.por_pagina || 100);
  const resumen = resultado?.resumen || {};
  const filtrosActivos = [
    ...(consulta.desde ? [{ clave: 'desde', etiqueta: `Desde: ${consulta.desde}` }] : []),
    ...(consulta.hasta ? [{ clave: 'hasta', etiqueta: `Hasta: ${consulta.hasta}` }] : []),
    ...(consulta.q ? [{ clave: 'q', etiqueta: `Búsqueda: ${consulta.q}` }] : []),
    ...(consulta.estado !== 'all' ? [{
      clave: 'estado',
      etiqueta: `Resultado: ${[['OK', 'Correcta'], ['ERROR', 'Error confirmado'], ['WARNING', 'Advertencia'],
        ['RUNNING', 'En curso'], ['REVISION', 'Errores pendientes de clasificación']].find(([value]) => value === consulta.estado)?.[1] || consulta.estado}`,
    }] : []),
  ];
  const quitarFiltro = (clave) => {
    const siguiente = { ...consulta, [clave]: clave === 'estado' ? 'all' : '' };
    setConsulta(siguiente);
    setDesde(siguiente.desde);
    setHasta(siguiente.hasta);
    setEstado(siguiente.estado);
    setBusqueda(siguiente.q);
    setPagina(1);
  };
  const limpiarFiltros = () => {
    const vacios = { desde: '', hasta: '', estado: 'all', q: '' };
    setDesde(vacios.desde);
    setHasta(vacios.hasta);
    setEstado(vacios.estado);
    setBusqueda(vacios.q);
    setConsulta(vacios);
    setPagina(1);
  };

  return <section className="informe-page" aria-labelledby="informe-titulo">
    <PageHeading id="informe-titulo" eyebrow="Auditoría" title="Informe de copias"
      description={<>Historial de copias del periodo. Los resultados ambiguos se identifican como <strong>errores pendientes de clasificación</strong>.</>}
      actions={<><button className="btn sec" type="button" onClick={() => generarPdf(true)} disabled={exportando || !total}>{exportando ? 'Preparando PDF…' : 'Imprimir'}</button><button className="btn" type="button" onClick={() => generarPdf(false)} disabled={exportando || !total}>{exportando ? 'Preparando PDF…' : 'Descargar PDF'}</button></>} />
    <BarraFiltros className="informe-filtros" title="Filtrar informe"
      search={{ id: 'informe-buscar', label: 'Buscar', value: busqueda, placeholder: 'Cliente, equipo, error o código', onChange: (event) => setBusqueda(event.target.value) }}
      fields={[
        { id: 'informe-desde', label: 'Desde', type: 'date', value: desde, max: hasta,
          onChange: (event) => setDesde(event.target.value) },
        { id: 'informe-hasta', label: 'Hasta', type: 'date', value: hasta, min: desde,
          onChange: (event) => setHasta(event.target.value) },
        { id: 'informe-estado', label: 'Resultado', type: 'select', value: estado,
          onChange: (event) => setEstado(event.target.value),
          options: [['all', 'Todos'], ['OK', 'Correcta'], ['ERROR', 'Error confirmado'],
            ['WARNING', 'Advertencia'], ['RUNNING', 'En curso'], ['REVISION', 'Errores pendientes de clasificación']] },
      ]}
      actions={<button className="btn sec" type="submit">Aplicar filtros</button>}
      count={`${total.toLocaleString('es-ES')} registros`} onSubmit={aplicar}
      activeFilters={filtrosActivos} onRemoveFilter={quitarFiltro} onClear={limpiarFiltros} />
    <div className="informe-resumen" aria-live="polite">
      <span>Correctas <strong>{resumen.OK || 0}</strong></span><span>Errores confirmados <strong>{resumen.ERROR || 0}</strong></span>
      <span>Advertencias <strong>{resumen.WARNING || 0}</strong></span><span>En curso <strong>{resumen.RUNNING || 0}</strong></span>
      <span>Pendientes clasificación <strong>{resumen.REVISION || 0}</strong></span>
    </div>
    {error && <div className="errbox" role="alert">{error} <button className="btn sec" type="button" onClick={() => cargar(pagina)}>Reintentar</button></div>}
    <div className="wrap informe-tabla-wrap"><table className="informe-tabla"><thead><tr>
      <th>Fecha</th><th>Cliente</th><th>Equipo</th><th>Software</th><th>Estado</th><th>Resultado original</th><th>Motivo / evidencia</th><th>Código</th><th>Fuente</th><th>Acción</th>
    </tr></thead><tbody>
      {(resultado?.copias || []).map((copy) => <tr key={copy.id}>
        <td className="nowrap">{copy.fecha}</td><td>{copy.cliente}</td><td>{copy.equipo}</td><td>{copy.software || '—'}</td>
        <td><Tag c={copy} />{copy.staging === 1 && <small className="informe-evidencia">Pendiente de importar</small>}</td><td>{copy.resultado_original || 'Desconocido'}</td>
        <td>{copy.desc_error || copy.clasificacion_evidencia || '—'}{copy.clasificacion_evidencia && copy.desc_error && <small className="informe-evidencia">Evidencia: {copy.clasificacion_evidencia}</small>}</td>
        <td>{copy.codigo || '—'}</td><td>{fuenteEtiqueta(copy.clasificacion_fuente)}</td>
        <td>{copy.equipo_id ? <button className="btn sec" type="button" onClick={() => {
          const grupo = copy.cliente_id
            ? `/clientes/${encodeURIComponent(String(copy.cliente_id))}`
            : '/clientes/sin-asignar';
          onNavigate(`${grupo}/equipos/${copy.equipo_id}`);
        }}>Ver equipo</button> : '—'}</td>
      </tr>)}
      {!resultado?.copias?.length && <tr><td colSpan="10" className="vacio">{cargando ? 'Cargando informe…' : 'No hay copias en el intervalo seleccionado.'}</td></tr>}
    </tbody></table></div>
    <Paginacion total={total} pagina={pagina} paginas={Math.max(1, Math.ceil(total / porPagina))}
      porPagina={porPagina} onCambiar={(page) => cargar(page)} etiqueta="copias del informe" />
  </section>;
}
