/**
 * Etiqueta visual del estado de una copia.
 */
import { ef, etiquetaEstado, severidad } from '../../../shared/lib/logic';

export default function Tag({ c }) {
  const k = ef(c);
  const sev = k === 'ERR' ? ` sev-${severidad(c)}` : '';
  return <span className={`tag ${k}${sev}`}>{etiquetaEstado(c)}</span>;
}
