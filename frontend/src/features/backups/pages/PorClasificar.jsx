import PageHeading from '../../../shared/ui/PageHeading';
import ClasificacionPendiente from '../components/ClasificacionPendiente';

export default function PorClasificar({ copias, cargando, error, onClasificar, onRecargar }) {
  const pendientes = (copias || []).filter((c) => c.estado === 'REVISION');
  return <section className="por-clasificar-page" aria-labelledby="por-clasificar-titulo" aria-busy={cargando}>
    <PageHeading id="por-clasificar-titulo" eyebrow="Copias" title="Por clasificar"
      description="Errores pendientes agrupados por descripcion para clasificarlos sin saturar el historial."
      actions={<button className="btn sec" type="button" disabled={cargando} onClick={onRecargar}>
        {cargando ? 'Actualizando...' : 'Actualizar'}
      </button>} />
    {error && <div className="errbox" role="alert">No se pudieron cargar las copias: {error} <button className="btn sec" onClick={onRecargar}>Reintentar</button></div>}
    {!error && !pendientes.length && <div className="empty-state" role="status">
      <strong>No hay copias por clasificar</strong>
      <span>Cuando llegue un resultado ambiguo aparecera aqui agrupado por tipo de fallo.</span>
    </div>}
    <ClasificacionPendiente copias={copias} onClasificar={onClasificar} modo="pagina" />
  </section>;
}
