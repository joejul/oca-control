import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import ConfirmDialog from '../components/ConfirmDialog';

describe('ConfirmDialog', () => {
  const baseProps = {
    open: true,
    title: 'Confirmá',
    rows: [['Horas', '2 h'] as [string, string]],
    onConfirm: vi.fn(),
    onCancel: vi.fn(),
  };

  it('no renderiza nada cuando open=false', () => {
    const { container } = render(<ConfirmDialog {...baseProps} open={false} />);
    expect(container).toBeEmptyDOMElement();
  });

  it('muestra los datos y el aviso de inmutabilidad', () => {
    render(<ConfirmDialog {...baseProps} />);
    expect(screen.getByText('Horas')).toBeInTheDocument();
    expect(screen.getByText('2 h')).toBeInTheDocument();
    expect(screen.getByText(/no se puede modificar/i)).toBeInTheDocument();
  });

  it('confirma solo cuando el usuario pulsa el botón', async () => {
    const onConfirm = vi.fn();
    render(<ConfirmDialog {...baseProps} onConfirm={onConfirm} />);
    expect(onConfirm).not.toHaveBeenCalled();
    await userEvent.click(screen.getByRole('button', { name: /confirmar y guardar/i }));
    expect(onConfirm).toHaveBeenCalledTimes(1);
  });

  it('respeta confirmDisabled', async () => {
    const onConfirm = vi.fn();
    render(<ConfirmDialog {...baseProps} onConfirm={onConfirm} confirmDisabled />);
    await userEvent.click(screen.getByRole('button', { name: /confirmar y guardar/i }));
    expect(onConfirm).not.toHaveBeenCalled();
  });
});
