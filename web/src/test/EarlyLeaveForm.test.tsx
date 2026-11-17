import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import EarlyLeaveForm from '../pages/collab/EarlyLeaveForm';

const apiMock = vi.hoisted(() => vi.fn());
vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');
  return { ...actual, api: apiMock };
});

function setup() {
  render(
    <MemoryRouter>
      <EarlyLeaveForm />
    </MemoryRouter>,
  );
  const time = (label: RegExp) => screen.getByLabelText(label) as HTMLInputElement;
  return {
    scheduled: time(/hora oficial/i),
    actual: time(/hora real/i),
    detail: screen.getByLabelText(/detalle/i) as HTMLInputElement,
    next: screen.getByRole('button', { name: /continuar/i }),
  };
}

describe('EarlyLeaveForm', () => {
  beforeEach(() => apiMock.mockReset());

  it('calcula las horas a reponer en vivo', () => {
    const { scheduled, actual } = setup();
    fireEvent.change(scheduled, { target: { value: '17:00' } });
    fireEvent.change(actual, { target: { value: '15:30' } });
    expect(screen.getByText(/horas a reponer/i).textContent).toContain('1 h 30 min');
  });

  it('avisa si la hora real no es anterior a la oficial', () => {
    const { scheduled, actual, next } = setup();
    fireEvent.change(scheduled, { target: { value: '15:00' } });
    fireEvent.change(actual, { target: { value: '16:00' } });
    expect(screen.getByText(/debe ser anterior/i)).toBeInTheDocument();
    expect(next).toBeDisabled();
  });

  it('exige el detalle antes de continuar', () => {
    const { scheduled, actual, detail, next } = setup();
    fireEvent.change(scheduled, { target: { value: '17:00' } });
    fireEvent.change(actual, { target: { value: '16:00' } });
    expect(next).toBeDisabled();
    fireEvent.change(detail, { target: { value: 'Cita médica' } });
    expect(next).toBeEnabled();
  });

  it('no guarda hasta confirmar en el diálogo', async () => {
    apiMock.mockResolvedValue({ minutes_owed: 60, balance: { due_minutes: 60 } });
    const { scheduled, actual, detail, next } = setup();
    fireEvent.change(scheduled, { target: { value: '17:00' } });
    fireEvent.change(actual, { target: { value: '16:00' } });
    fireEvent.change(detail, { target: { value: 'Cita médica' } });

    await userEvent.click(next);
    expect(apiMock).not.toHaveBeenCalled();
    expect(screen.getByRole('alertdialog')).toBeInTheDocument();

    await userEvent.click(screen.getByRole('button', { name: /confirmar y guardar/i }));
    expect(apiMock).toHaveBeenCalledWith(
      '/early-leave',
      expect.objectContaining({
        method: 'POST',
        body: expect.objectContaining({ scheduled_end: '17:00', actual_end: '16:00', note: 'Cita médica' }),
      }),
    );
  });
});
