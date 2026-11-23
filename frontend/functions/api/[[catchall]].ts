import { handleProxy } from "../_proxy";

export const onRequest = async (context: any) => {
  return handleProxy(context);
};
