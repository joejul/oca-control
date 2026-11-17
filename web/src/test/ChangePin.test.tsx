import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiError } from '../api';
import ChangePin from '../pages/ChangePin';

const apiMock = vi.hoisted(() => vi.fn());
vi.mock('../api', async () => {
  const actual = await vi.importActual<typeof import('../api')>('../api');
  return { ...actual, api: apiMock };
});

function setup() {
  render(
    <MemoryRouter>
      <ChangePin />
    </MemoryRouter>,
  );
  return {
    current: screen.getByLabelText(/pin actual/i),
    next: screen.getByLabelText(/^pin nuevo/i),
    again: screen.getByLabelText(/repet/i),
    submit: screen.getByRole('button', { name: /cambiar pin/i }),
  };
}

describe('ChangePin', () => {
  beforeEach(() => {
    apiMock.mockReset();
  });

  it('no deja enviar hasta que todo sea válido', async () => {
    const { current, next, again, submit } = setup();
    expect(submit).toBeDisabled();

    await userEvent.type(current, '091192');
    await userEvent.type(next, '12345'); // formato inválido (sin letra)
    expect(screen.getByText(/1 letra y 4 números \(ej/i, { selector: '.error-box' })).toBeInTheDocument();
    expect(submit).toBeDisabled();

    await userEvent.clear(next);
    await userEvent.type(next, 'a1234');
    await userEvent.type(again, 'a1235');
    expect(screen.getByText(/no coinciden/i)).toBeInTheDocument();
    expect(submit).toBeDisabled();

    await userEvent.clear(again);
    await userEvent.type(again, 'a1234');
    expect(submit).toBeEnabled();
  });

  it('avisa si el PIN nuevo es igual al actual', async () => {
    const { current, next, again, submit } = setup();
    await userEvent.type(current, 'a1234');
    await userEvent.type(next, 'a1234');
    await userEvent.type(again, 'a1234');
    expect(screen.getByText(/distinto al actual/i)).toBeInTheDocument();
    expect(submit).toBeDisabled();
  });

  it('envía los PIN y muestra la confirmación', async () => {
    apiMock.mockResolvedValue({ ok: true });
    const { current, next, again, submit } = setup();
    await userEvent.type(current, 'a1234');
    await userEvent.type(next, 'z4321');
    await userEvent.type(again, 'z4321');
    await userEvent.click(submit);

    expect(apiMock).toHaveBeenCalledWith('/me/pin', {
      method: 'POST',
      body: { current_pin: 'a1234', new_pin: 'z4321' },
    });
    expect(await screen.findByText(/pin actualizado/i)).toBeInTheDocument();
  });

  it('muestra el error del servidor (PIN actual incorrecto) y deja reintentar', async () => {
    apiMock.mockImplementation(async () => {
      throw new ApiError(422, 'El PIN actual no es correcto.');
    });
    const { current, next, again, submit } = setup();
    await userEvent.type(current, 'x0000');
    await userEvent.type(next, 'z4321');
    await userEvent.type(again, 'z4321');
    await userEvent.click(submit);

    expect(await screen.findByText('El PIN actual no es correcto.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /cambiar pin/i })).toBeEnabled();
  });
});
