import Tag from './Tag';
import Paginacion from './Paginacion';
import { usePagination } from '../hooks/usePagination';

export default function Historial({ c, hist, r }) {
  const pagina = usePagination(hist, 20, c.id);
  return (
    <div className="notas">
      <h3>Historial del equipo {c.equipo} ({hist.length + 1} copias)</h3>
      {r > 1 && <div className="errbox">🔁 Este equipo acumula {r} errores: puede haber un problema de fondo.</div>}
      {hist.length === 0
        ? <small className="mut">Sin otras copias registradas.</small>
        : pagina.items.map((x) => (
            <div className="hrow" key={x.id}>
              <span>{x.fecha}</span>
              <Tag c={x} />
            </div>
          ))}
      <Paginacion total={hist.length} pagina={pagina.page} paginas={pagina.pageCount}
        porPagina={pagina.pageSize} onCambiar={pagina.setPage} etiqueta="copias del equipo" />
    </div>
  );
}
