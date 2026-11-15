{{--
    Modal de registrar e de corrigir pagamento, ligado à API pelo pages.js (createPaymentDialog).
    Fica na tela de Pagamentos e no detalhe da Agenda, dentro do elemento que o pages.js inicia:
    - registrar parte de um atendimento concluído sem pagamento, com o valor já preenchido pelo preço dele.
      Quando o cliente tem prêmio de fidelidade disponível, aparecem o aviso e a caixa "Usar o prêmio de
      fidelidade", que trava o valor no que sobra depois do desconto;
    - corrigir (só o admin) muda o valor, a forma e as observações de um pagamento.
    As formas de pagamento vêm do PaymentMethod::catalog(). Quem decide as regras é a API.
--}}
@use('App\Enums\PaymentMethod')

<div class="modal fade" tabindex="-1" aria-labelledby="payment-form-title" aria-hidden="true" data-payment-modal>
    <div class="modal-dialog">
        <form class="modal-content" novalidate data-payment-form>
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="payment-form-title" data-payment-form-title>Registrar pagamento</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
            </div>
            <div class="modal-body">
                <div data-payment-form-alerts></div>

                {{-- Cliente, barbeiro, serviço, data e horário do atendimento. --}}
                <dl class="mb-3" data-payment-summary></dl>

                <p class="small text-body-secondary">Campos com <span class="text-danger">*</span> são obrigatórios.</p>

                {{--
                    Fidelidade: ao abrir o registro, o pages.js confere a do cliente sem contar este atendimento
                    (GET /api/clients/{id}/loyalty?appointment_id=) e só mostra esta parte com prêmio disponível.
                --}}
                <div class="mb-3" data-loyalty-reward hidden>
                    <p class="alert alert-success py-2 mb-2" id="payment-reward-notice" data-loyalty-notice></p>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="payment-use-reward" name="use_loyalty_reward" value="1"
                            aria-describedby="payment-reward-notice">
                        <label class="form-check-label" for="payment-use-reward">Usar o prêmio de fidelidade</label>
                        <div class="invalid-feedback" id="payment-use-reward-error" data-error-for="use_loyalty_reward"></div>
                    </div>
                </div>
                <p class="small text-body-secondary" data-loyalty-status hidden></p>

                <div class="mb-3">
                    <label for="payment-amount" class="form-label">Valor (R$)<span class="text-danger" aria-hidden="true"> *</span></label>
                    <input type="number" class="form-control" id="payment-amount" name="amount" min="0" max="999999.99" step="0.01"
                        inputmode="decimal" aria-required="true" aria-describedby="payment-amount-hint">
                    <div class="form-text" id="payment-amount-hint" data-payment-amount-hint></div>
                    <div class="invalid-feedback" id="payment-amount-error" data-error-for="amount"></div>
                </div>

                <div class="mb-3">
                    <label for="payment-method" class="form-label">Forma de pagamento<span class="text-danger" aria-hidden="true"> *</span></label>
                    <select class="form-select" id="payment-method" name="method" aria-required="true">
                        <option value="">Escolha a forma</option>
                        @foreach (PaymentMethod::catalog() as $value => $method)
                            <option value="{{ $value }}">{{ $method['label'] }}</option>
                        @endforeach
                    </select>
                    <div class="invalid-feedback" id="payment-method-error" data-error-for="method"></div>
                </div>

                <div class="mb-1">
                    <label for="payment-notes" class="form-label">Observações <span class="text-body-secondary">(opcional)</span></label>
                    <textarea class="form-control" id="payment-notes" name="notes" rows="2" maxlength="1000"
                        aria-describedby="payment-notes-hint"></textarea>
                    <div class="form-text" id="payment-notes-hint">Até 1000 caracteres. O cliente também pode ver este texto.</div>
                    <div class="invalid-feedback" id="payment-notes-error" data-error-for="notes"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-primary" data-payment-submit>Registrar pagamento</button>
            </div>
        </form>
    </div>
</div>
