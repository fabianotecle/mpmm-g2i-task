# MPMM public task snapshot

This is a public, MIT-licensed snapshot of the My Phone My Money Laravel
application, prepared from source commit `637261733ddff926eb537b2e3fa50b0ea0cddc8a` for a G2i task.
It comes from an active application.

Private source assets, customer seed data, operational phone defaults, and
environment values have been removed or replaced. This repository starts with
a new history so omitted files are not recoverable from older commits.

Main application areas: `app/`, `routes/`, `config/`, `database/migrations/`,
`resources/`, and `tests/`.

The public seeder creates content only; it does not create an administrator.
Administrative users must be provisioned explicitly. Admin, finance, and TIC
accounts have different API permissions, while rotative-wallet management keeps
its separate configured manager check. Financial document links are signed and
expire after seven days by default (`DOCUMENTS_LINK_TTL_MINUTES`). For WhatsApp
link delivery, `DOCUMENTS_PUBLIC_URL` must point to this application's public
entry point and `APP_KEY` must remain stable until issued links expire.

---

# 📱💰 My Phone My Money (MpMM)

<p align="center">
  <img src="docs/mpmm.jpg" alt="MpMM Logo" width="250">
</p>

<p align="center">
  <strong>Meu Celular. Meu Dinheiro.</strong>
</p>

<p align="center">
  Transformando conversas em movimentações financeiras.
</p>

---

## 🚀 Sobre o Projeto

O My Phone My Money (MpMM) é uma plataforma financeira conversacional criada para simplificar o acesso a serviços financeiros através de jornadas guiadas.

O projeto nasce com uma arquitetura web/API, permitindo que as regras de negócio sejam desenvolvidas, testadas e evoluídas antes da integração definitiva com canais externos, como WhatsApp e outros meios de mensageria.

A primeira versão será construída com uma interface web simulando a experiência conversacional, permitindo validar fluxos como cadastro, menu principal, depósitos, transferências, resgates PIX, extrato e painel administrativo.

---

## 🧠 Conceito

O MpMM não parte de uma tela tradicional de sistema financeiro.

Ele parte de uma conversa.

Cada interação do usuário representa um passo dentro de uma jornada, onde o sistema entende o estado atual, processa a resposta e retorna a próxima ação possível.

---

## 🏗️ Arquitetura Inicial

O sistema será desenvolvido com foco em API First.

A interface web inicial funcionará como um simulador de conversa, consumindo os mesmos endpoints que futuramente poderão ser utilizados por integrações externas.

Fluxo inicial:

```txt
Telefone
↓
Identificação do cliente
↓
Criação de sessão
↓
Cadastro ou Menu Principal
↓
Jornadas financeiras
```

---

## 🔐 Identificação e Sessão

O MpMM não utilizará login tradicional com e-mail e senha.

A identificação inicial será feita pelo telefone informado pelo usuário.

Após validar o telefone, o sistema cria uma sessão segura para controlar a jornada conversacional daquele cliente.

---

## ✨ Funcionalidades Planejadas

### 👤 Cadastro de Clientes

- Cadastro guiado
- Identificação por telefone
- Coleta de dados básicos
- Ativação da jornada inicial

### 💵 Depósitos

- Escolha de valor
- Escolha de meio de pagamento
- Geração de cobrança
- Confirmação da operação

### 🔄 Transferências

- Transferência entre usuários
- Confirmação de valor e destinatário
- Possibilidade de envio anônimo
- Suporte futuro para transferência em lote

### 🏦 Resgate PIX

- Consulta de saldo disponível
- Confirmação de chave PIX
- Solicitação de resgate
- Registro da operação

### 📄 Extrato

- Consulta de movimentações
- Resumo financeiro
- Histórico de operações

### 🎁 Convites

- Geração de convite personalizado
- Compartilhamento com novos usuários
- Possibilidade futura de bonificações

### ⚙️ Painel Administrativo

- Consulta de clientes
- Gestão de limites
- Acompanhamento de operações
- Rotas administrativas específicas

---

## 🛣️ Roadmap Inicial

- [x] Criação do repositório
- [x] Definição da identidade inicial
- [ ] Instalação do Laravel
- [ ] Configuração do ambiente
- [ ] Configuração do banco de dados
- [ ] Implementação da autenticação por sessão/token
- [ ] Criação da tabela de clientes
- [ ] Criação da tabela de sessões conversacionais
- [ ] Endpoint inicial de identificação por telefone
- [ ] Fluxo de cadastro
- [ ] Menu principal
- [ ] Simulador web de conversa
- [ ] Primeiras jornadas financeiras
- [ ] Integração futura com WhatsApp

---

## 📖 Filosofia

Acreditamos que operações financeiras devem ser tão simples quanto enviar uma mensagem.

O MpMM nasce com a missão de aproximar pessoas e serviços financeiros através de experiências intuitivas, acessíveis e centradas na conversa.

---

## © My Phone My Money

**Meu Celular. Meu Dinheiro.**
