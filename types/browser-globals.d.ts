declare interface EpayAdminConfig {
  ajaxUrl: string;
  followUpNonce: string;
  strings: EpayAdminStrings;
  wafTestNonce: string;
}

declare interface EpayAdminStrings {
  copied?: string;
  copy?: string;
  failed?: string;
  followUpChannel?: string;
  followUpCode?: string;
  followUpMethod?: string;
  followUpOrder?: string;
  followUpTesting?: string;
  followUpTime?: string;
  wafBlocked?: string;
  wafBody?: string;
  wafHeaders?: string;
  wafHttpStatus?: string;
  wafNetworkErr?: string;
  wafNoResponse?: string;
  wafPass?: string;
  wafServerError?: string;
  wafTarget?: string;
  wafTesting?: string;
  wafTransport?: string;
  wafUnexpected?: string;
}

declare interface EpayAjaxData {
  body_snippet?: string;
  channel?: string;
  detail?: string;
  headers?: Record<string, string>;
  message?: string;
  payment_method?: string;
  response_code?: string;
  status?: number;
  status_label?: string;
  target_url?: string;
  transaction_at?: string;
  verdict?: "blocked" | "no_response" | "pass" | "server_error" | "unexpected_response";
}

declare interface EpayAjaxResponse {
  data: EpayAjaxData;
  success: boolean;
}

declare interface EpayBlocksSettings {
  description: string;
  icon: string;
  supports: string[];
  title: string;
}

declare interface EpayVirtualElement {
  readonly props: Readonly<Record<string, EpayRenderable>>;
  readonly type: string | EpayRenderableFactory;
}

declare type EpayRenderable = EpayVirtualElement | EpayRenderable[] | boolean | null | number | string;
declare type EpayRenderableFactory = () => EpayRenderable;

declare interface Window {
  jQuery: (element: HTMLElement) => { on: (events: string, handler: () => void) => void };
  epayPaycenterCheckout: { installmentsChanged: string };
  epayPaycenterAdmin?: EpayAdminConfig;
  wc?: {
    wcBlocksRegistry?: {
      registerPaymentMethod: (configuration: {
        ariaLabel: string;
        canMakePayment: () => boolean;
        content: EpayRenderable;
        edit: EpayRenderable;
        label: EpayRenderable;
        name: string;
        supports: { features: string[] };
      }) => void;
    };
    wcSettings?: {
      getSetting: (name: string, fallback: EpayBlocksSettings) => EpayBlocksSettings;
    };
  };
  wp?: {
    element?: {
      createElement: (
        type: string | EpayRenderableFactory,
        properties: Record<string, string> | null,
        ...children: EpayRenderable[]
      ) => EpayVirtualElement;
    };
    htmlEntities?: {
      decodeEntities: (value: string) => string;
    };
  };
}
