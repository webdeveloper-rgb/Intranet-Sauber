import { useCallback, useEffect, useState } from 'react';
import { api } from '../api';
import Paginacion from './Paginacion';
import Tag from './Tag';

const fechaLocal = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const fechaInicial = () => {
  const date = new Date();
  date.setDate(date.getDate() - 30);
  return fechaLocal(date);
};
const csvCell = (value) => `"${String(value ?? '').replace(/"/g, '""').replace(/[\r\n]+/g, ' ')}"`;

export default function InformeCopias() {
  const [desde, setDesde] = useState(fechaInicial);
  const [hasta, setHasta] = useState(() => fechaLocal(new Date()));
  const [estado, setEstado] = useState('all');
  const [consulta, setConsulta] = useState({ desde: fechaInicial(), hasta: fechaLocal(new Date()), estado: 'all' });
  const [pagina, setPagina] = useState(1);
  const [resultado, setResultado] = useState(null);
  const [cargando, setCargando] = useState(true);
  const [exportando, setExportando] = useState(false);
  const [error, setError] = useState('');

  const cargar = useCallback(async (page = 1) => {
    setCargando(true);
    setError('');
    try {
      const data = await api(`informe_copias&desde=${consulta.desde}&hasta=${consulta.hasta}&estado=${consulta.estado}&page=${page}`);
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
    setConsulta({ desde, hasta, estado });
    setPagina(1);
  };

  const exportar = async () => {
    if (exportando || !resultado?.total) return;
    setExportando(true);
    setError('');
    try {
      const rows = [];
      const pages = Math.ceil(resultado.total / (resultado.por_pagina || 100));
      for (let page = 1; page <= pages; page += 1) {
        const data = await api(`informe_copias&desde=${consulta.desde}&hasta=${consulta.hasta}&estado=${consulta.estado}&page=${page}`);
        rows.push(...(data.copias || []));
      }
      const header = ['Fecha', 'Cliente', 'Equipo', 'Software', 'Estado panel', 'Resultado original', 'Motivo', 'Código', 'Fuente de clasificación', 'Evidencia', 'Destino', 'Tamaño', 'Duración', 'Origen de registro'];
      const lines = [header, ...rows.map((item) => [item.fecha, item.cliente, item.equipo, item.software,
        item.estado, item.resultado_original || 'DESCONOCIDO', item.desc_error, item.codigo,
        item.clasificacion_fuente || 'Sin registrar', item.clasificacion_evidencia, item.destino, item.tamano, item.duracion,
        item.staging ? 'Pendiente de importar' : 'Historial'])]
        .map((line) => line.map(csvCell).join(';')).join('\r\n');
      const blob = new Blob(['\ufeff', lines], { type: 'text/csv;charset=utf-8' });
      const url = URL.createObjectURL(blob);
      const link = document.createElement('a');
      link.href = url;
      link.download = `informe-copias-${consulta.desde}-a-${consulta.hasta}.csv`;
      link.click();
      URL.revokeObjectURL(url);
    } catch (e) {
      setError(e.message || 'No se pudo exportar el informe.');
    } finally {
      setExportando(false);
    }
  };

  const total = Number(resultado?.total || 0);
  const porPagina = Number(resultado?.por_pagina || 100);
  const resumen = resultado?.resumen || {};

  return <section className="informe-page" aria-labelledby="informe-titulo">
    <div className="page-heading"><div><span className="eyebrow">Auditoría</span><h2 id="informe-titulo">Informe de copias</h2>
      <p>Historial de copias del periodo. Los resultados ambiguos se identifican como <strong>por clasificar</strong>.</p></div>
      <button className="btn" type="button" onClick={exportar} disabled={exportando || !total}>{exportando ? 'Preparando CSV…' : 'Exportar CSV'}</button></div>
    <form className="informe-filtros" onSubmit={aplicar}>
      <label>Desde<input type="date" value={desde} max={hasta} onChange={(event) => setDesde(event.target.value)} /></label>
      <label>Hasta<input type="date" value={hasta} min={desde} onChange={(event) => setHasta(event.target.value)} /></label>
      <label>Resultado<select value={estado} onChange={(event) => setEstado(event.target.value)}>
        <option value="all">Todos</option><option value="OK">Correcta</option><option value="ERROR">Error confirmado</option>
        <option value="WARNING">Advertencia</option><option value="RUNNING">En curso</option><option value="REVISION">Por clasificar</option>
      </select></label>
      <button className="btn sec" type="submit">Aplicar filtros</button>
      <span className="informe-conteo">{total.toLocaleString('es-ES')} registros</span>
    </form>
    <div className="informe-resumen" aria-live="polite">
      <span>Correctas <strong>{resumen.OK || 0}</strong></span><span>Errores confirmados <strong>{resumen.ERROR || 0}</strong></span>
      <span>Advertencias <strong>{resumen.WARNING || 0}</strong></span><span>En curso <strong>{resumen.RUNNING || 0}</strong></span>
      <span>Por clasificar <strong>{resumen.REVISION || 0}</strong></span>
    </div>
    {error && <div className="errbox" role="alert">{error} <button className="btn sec" type="button" onClick={() => cargar(pagina)}>Reintentar</button></div>}
    <div className="wrap informe-tabla-wrap"><table className="informe-tabla"><thead><tr>
      <th>Fecha</th><th>Cliente</th><th>Equipo</th><th>Software</th><th>Estado</th><th>Resultado original</th><th>Motivo / evidencia</th><th>Código</th><th>Fuente</th>
    </tr></thead><tbody>
      {(resultado?.copias || []).map((copy) => <tr key={copy.id}>
        <td className="nowrap">{copy.fecha}</td><td>{copy.cliente}</td><td>{copy.equipo}</td><td>{copy.software || '—'}</td>
        <td><Tag c={copy} />{copy.staging === 1 && <small className="informe-evidencia">Pendiente de importar</small>}</td><td>{copy.resultado_original || 'Desconocido'}</td>
        <td>{copy.desc_error || copy.clasificacion_evidencia || '—'}{copy.clasificacion_evidencia && copy.desc_error && <small className="informe-evidencia">Evidencia: {copy.clasificacion_evidencia}</small>}</td>
        <td>{copy.codigo || '—'}</td><td>{copy.clasificacion_fuente || 'Sin registrar'}</td>
      </tr>)}
      {!resultado?.copias?.length && <tr><td colSpan="9" className="vacio">{cargando ? 'Cargando informe…' : 'No hay copias en el intervalo seleccionado.'}</td></tr>}
    </tbody></table></div>
    <Paginacion total={total} pagina={pagina} paginas={Math.max(1, Math.ceil(total / porPagina))}
      porPagina={porPagina} onCambiar={(page) => cargar(page)} etiqueta="copias del informe" />
  </section>;
}
