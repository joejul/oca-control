import { useEffect, useRef } from 'react';
import type { ReactNode } from 'react';

type Props = {
  open: boolean;
  title: string;
  /** Filas de datos a confirmar: [etiqueta, valor] */
  rows: Array<[string, ReactNode]>;
  warning?: string;
  confirmLabel?: string;
  busy?: boolean;
  /** Contenido extra (ej. un campo de motivo) dentro del diálogo. */
  children?: ReactNode;
  confirmDisabled?: boolean;
  onConfirm: () => void;
  onCancel: () => void;
};

/**
 * Alert de confirmacion obligatorio antes de guardar un registro inmutable.
 * Muestra exactamente los datos que se van a guardar.
 */
export default function ConfirmDialog({
  open,
  title,
  rows,
  warning = 'Este registro NO se puede modificar después de guardar.',
  confirmLabel = 'Confirmar y guardar',
  busy = false,
  children,
  confirmDisabled = false,
  onConfirm,
  onCancel,
}: Props) {
  const confirmRef = useRef<HTMLButtonElement>(null);

  useEffect(() => {
    if (open) confirmRef.current?.focus();
  }, [open]);

  useEffect(() => {
    if (!open) return;
    function onKey(e: KeyboardEvent) {
      if (e.key === 'Escape' && !busy) onCancel();
    }
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open, busy, onCancel]);

  if (!open) return null;

  return (
    <div className="overlay" role="presentation" onClick={() => !busy && onCancel()}>
      <div
        className="dialog"
        role="alertdialog"
        aria-modal="true"
        aria-label={title}
        onClick={(e) => e.stopPropagation()}
      >
        <h2>{title}</h2>
        <dl className="confirm-rows">
          {rows.map(([label, value]) => (
            <div key={label}>
              <dt>{label}</dt>
              <dd>{value}</dd>
            </div>
          ))}
        </dl>
        {children && <div className="dialog-extra">{children}</div>}
        <p className="warning">⚠️ {warning}</p>
        <div className="dialog-actions">
          <button type="button" className="btn ghost" onClick={onCancel} disabled={busy}>
            Cancelar
          </button>
          <button
            ref={confirmRef}
            type="button"
            className="btn primary"
            onClick={onConfirm}
            disabled={busy || confirmDisabled}
          >
            {busy ? 'Guardando…' : confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
