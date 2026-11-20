/**
 * Etiqueta visual del estado de una copia.
 */
import { ef, LBL } from '../../../shared/lib/logic';

export default function Tag({ c }) {
  const k = ef(c);
  return <span className={'tag ' + k}>{c.codigo === 'SIN_COPIA' ? 'ERROR CRÍTICO' : LBL[k]}</span>;
}
