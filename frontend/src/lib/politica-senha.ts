export const MENSAGEM_POLITICA_SENHA =
  "A senha deve ter no mínimo 8 caracteres, com letra maiúscula, letra minúscula e caractere especial.";

export function senhaAtendePolitica(senha: string): boolean {
  return Array.from(senha).length >= 8
    && /\p{Lu}/u.test(senha)
    && /\p{Ll}/u.test(senha)
    && /[\p{P}\p{S}]/u.test(senha);
}
